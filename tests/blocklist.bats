#!/usr/bin/env bats
# Tests for the blocklists in the firewall (E-170). The syntax check needs
# root, because nft -c talks to the kernel.

ROOT="${BATS_TEST_DIRNAME}/.."

setup() {
  # shellcheck source=server/lib/common.sh
  source "${ROOT}/server/lib/common.sh"
  # shellcheck source=server/lib/firewall.sh
  source "${ROOT}/server/lib/firewall.sh"
  owp_die() { printf 'die: %s\n' "$1"; exit 1; }
  LIST="${BATS_TEST_TMPDIR}/list"
  RULES="${BATS_TEST_TMPDIR}/blocklist.nft"
}

valid() {
  printf 'block\t%s\n' "$1" >"$LIST"
  ( owp_blocklist_render "$LIST" "$RULES" ) >/dev/null
}

@test "entries must be networks with a sensible prefix" {
  valid "198.51.100.0/24"
  valid "203.0.113.5/32"
  valid "2001:db8:1::/48"
  valid "2001:db8::1/128"
  ! valid "198.51.100.0"
  ! valid "10.0.0.0/7"
  ! valid "0.0.0.0/0"
  ! valid "256.1.1.1/32"
  ! valid "01.2.3.0/24"
  ! valid "2001:db8::/15"
  ! valid "198.51.100.0/24; drop"
  ! valid "2001:DB8::/32"
  ! valid "1.2.3.4/24/1"
}

@test "the list becomes sets with exceptions before the drop" {
  printf 'block\t198.51.100.0/24\nblock\t192.0.2.0/24\nblock\t2001:db8:1::/48\nallow\t203.0.113.5/32\n' >"$LIST"
  owp_blocklist_render "$LIST" "$RULES"
  [ "$OWP_BL_COUNT_BLOCK" -eq 3 ]
  [ "$OWP_BL_COUNT_ALLOW" -eq 1 ]
  grep -q '198.51.100.0/24,' "$RULES"
  grep -q '192.0.2.0/24$' "$RULES"
  grep -q 'priority filter - 10; policy accept;' "$RULES"
  # Exceptions come before the drop and never accept anything else.
  [ "$(grep -n '@allow4 accept' "$RULES" | cut -d: -f1)" -lt "$(grep -n 'saddr @block4 update' "$RULES" | cut -d: -f1)" ]
  ! grep -q 'policy drop' "$RULES"
  if [ "$(id -u)" -eq 0 ] && command -v nft >/dev/null; then
    nft -c -f "$RULES"
  fi
}

@test "a set with one entry and empty sets are valid" {
  printf 'block\t198.51.100.0/24\n' >"$LIST"
  owp_blocklist_render "$LIST" "$RULES"
  ! grep -q '^ *,' "$RULES"
  grep -q '^    198.51.100.0/24$' "$RULES"
  [ "$(grep -c '^add element' "$RULES")" -eq 1 ]
  if [ "$(id -u)" -eq 0 ] && command -v nft >/dev/null; then
    nft -c -f "$RULES"
  fi
}

@test "broken lines stop the script" {
  printf 'block\t198.51.100.0/24\textra\n' >"$LIST"
  run owp_blocklist_render "$LIST" "$RULES"
  [[ "$output" == *"die: invalid_entry"* ]]

  printf 'drop\t198.51.100.0/24\n' >"$LIST"
  run owp_blocklist_render "$LIST" "$RULES"
  [[ "$output" == *"die: invalid_entry"* ]]

  printf 'block\t198.51.100.0/24 }\n' >"$LIST"
  run owp_blocklist_render "$LIST" "$RULES"
  [[ "$output" == *"die: invalid_entry"* ]]
}

@test "the script and its unit are in place" {
  grep -q '/opt/owpanel/bin/server-blocklist' "${ROOT}/server/templates/sudoers/owpanel-agent"
  grep -q 'ConditionPathExists=/etc/owpanel/blocklist.nft' "${ROOT}/server/templates/systemd/owpanel-blocklist.service"
  grep -q 'owp_blocklist_unit' "${ROOT}/server/provision/modules/firewall.sh"
}

@test "a reload swaps lists and rules but keeps the hit counts" {
  printf 'block\t198.51.100.0/24\n' >"$LIST"
  owp_blocklist_render "$LIST" "$RULES"
  grep -q 'set hits4 { type ipv4_addr; size 65535; flags dynamic,timeout; timeout 604800s; }' "$RULES"
  grep -q 'ip saddr @block4 update @hits4 { ip saddr counter } counter drop' "$RULES"
  grep -q '^flush chain inet owpanel_block input$' "$RULES"
  grep -q '^flush set inet owpanel_block block4$' "$RULES"
  ! grep -q 'flush set inet owpanel_block hits' "$RULES"
  ! grep -q 'delete table' "$RULES"
  if [ "$(id -u)" -eq 0 ] && command -v nft >/dev/null && nft list tables >/dev/null 2>&1; then
    nft delete table inet owpanel_block 2>/dev/null || true
    nft -f "$RULES"
    nft add element inet owpanel_block hits4 '{ 203.0.113.9 counter packets 7 bytes 420 }'
    nft -f "$RULES"
    [ "$(nft list chain inet owpanel_block input | grep -c 'drop')" -eq 2 ]
    command -v jq >/dev/null || skip "jq is missing"
    result="{$(owp_blocklist_hits 10)}"
    [ "$(printf '%s' "$result" | jq -c '[.addresses, .packets, .bytes, .top[0][0], .top[0][1], .counting]')" = '[1,7,420,"203.0.113.9",7,true]' ]
    nft delete table inet owpanel_block
  fi
}

@test "hits without a table are empty" {
  command -v jq >/dev/null || skip "jq is missing"
  nft() { return 1; }
  [ "{$(owp_blocklist_hits 10)}" = '{"addresses":0,"packets":0,"bytes":0,"top":[],"counting":false}' ]
}
