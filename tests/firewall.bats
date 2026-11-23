#!/usr/bin/env bats
# Tests for the nftables firewall. The syntax check needs root, because nft
# talks to the kernel even with -c; it is skipped otherwise.

ROOT="${BATS_TEST_DIRNAME}/.."

setup() {
  # shellcheck source=server/lib/common.sh
  source "${ROOT}/server/lib/common.sh"
  # shellcheck source=server/lib/site.sh
  source "${ROOT}/server/lib/site.sh"
  # shellcheck source=server/lib/firewall.sh
  source "${ROOT}/server/lib/firewall.sh"
  RULES="${BATS_TEST_TMPDIR}/firewall.nft"
  export OWP_FW_TEMPLATE="${ROOT}/server/templates/nftables/owpanel.nft"
}

@test "joins SSH ports for the template" {
  [ "$(owp_join_ports 22)" = "22" ]
  [ "$(owp_join_ports 22 2222)" = "22, 2222" ]
}

@test "renders the firewall with all required ports" {
  owp_firewall_render "$RULES" "22, 2222"
  grep -q 'policy drop;' "$RULES"
  grep -q 'tcp dport { 22, 2222 } accept comment "OWPanel SSH"' "$RULES"
  grep -q 'tcp dport { 80, 443 } accept' "$RULES"
  grep -q 'udp dport 443 accept comment "OWPanel HTTP/3"' "$RULES"
  grep -q 'ct state established,related accept' "$RULES"
  grep -q 'nd-neighbor-solicit' "$RULES"
}

@test "the rules replace only the OWPanel table" {
  owp_firewall_render "$RULES" "22"
  ! grep -q 'flush ruleset' "$RULES"
  [ "$(grep -cE '^table ' "$RULES")" -eq 2 ]
  ! grep -E '^(table|delete table) ' "$RULES" | grep -qv 'inet owpanel'
}

@test "the rendered rules pass nft -c" {
  [ "$(id -u)" -eq 0 ] || skip "nft -c needs root"
  command -v nft || skip "nft not installed"
  owp_firewall_render "$RULES" "22, 2222"
  nft -c -f "$RULES"
}

@test "firewall-confirm takes no parameters" {
  [ -r /opt/owpanel/lib/firewall.sh ] || skip "OWPanel is not installed here"
  run "${ROOT}/server/bin/firewall-confirm" --force
  [ "$status" -eq 1 ]
  [[ "${lines[-1]}" == *'"error":"invalid_arguments"'* ]]
}

@test "own rules become nft rules" {
  [ "$(owp_firewall_rule $'tcp\t3306\t203.0.113.5/32' 1)" = '        ip saddr 203.0.113.5/32 tcp dport 3306 accept comment "OWPanel rule 1"' ]
  [ "$(owp_firewall_rule $'udp\t51820\tany' 2)" = '        udp dport 51820 accept comment "OWPanel rule 2"' ]
  [ "$(owp_firewall_rule $'tcp\t8000-8010\t2001:db8::/64' 3)" = '        ip6 saddr 2001:db8::/64 tcp dport 8000-8010 accept comment "OWPanel rule 3"' ]
  [ "$(owp_firewall_rule $'tcp\t08080\tany' 4)" = '        tcp dport 8080 accept comment "OWPanel rule 4"' ]
}

@test "invalid own rules are refused" {
  local line
  for line in $'icmp\t1\tany' $'tcp\t0\tany' $'tcp\t70000\tany' $'tcp\t90-80\tany' $'tcp\t22\t1.2.3.4' \
    $'tcp\t22\t1.2.3.4/32; flush ruleset' $'tcp\t22\tany\textra' $'tcp\t22,23\tany' $'tcp\t22\t:abc' 'tcp 22 any'; do
    run owp_firewall_rule "$line" 1
    [ "$status" -eq 1 ] || { echo "accepted: $line"; return 1; }
  done
}

@test "own rules are rendered into the table and pass nft -c" {
  printf 'tcp\t3306\t203.0.113.5/32\nudp\t51820\tany\n\ntcp\t8000-8010\t2001:db8::/64\n' >"${BATS_TEST_TMPDIR}/rules.list"
  owp_firewall_render "$RULES" "22" "${BATS_TEST_TMPDIR}/rules.list"
  grep -q 'ip saddr 203.0.113.5/32 tcp dport 3306 accept comment "OWPanel rule 1"' "$RULES"
  grep -q 'ip6 saddr 2001:db8::/64 tcp dport 8000-8010 accept comment "OWPanel rule 3"' "$RULES"
  # Own rules come after the fixed ports and before the drop counter.
  [ "$(grep -n 'OWPanel HTTP/3' "$RULES" | cut -d: -f1)" -lt "$(grep -n 'OWPanel rule 1' "$RULES" | cut -d: -f1)" ]
  [ "$(grep -n 'OWPanel rule 3' "$RULES" | cut -d: -f1)" -lt "$(grep -n 'OWPanel dropped' "$RULES" | cut -d: -f1)" ]
  if [ "$(id -u)" -eq 0 ] && command -v nft >/dev/null; then
    nft -c -f "$RULES"
  fi
}

@test "a missing rules list renders only the fixed ports" {
  owp_firewall_render "$RULES" "22" "${BATS_TEST_TMPDIR}/missing.list"
  grep -q '# no own rules' "$RULES"
  ! grep -q 'OWPanel rule' "$RULES"
}

@test "an invalid line in the list stops the rendering" {
  printf 'tcp\t3306\tany\ntcp\t3306\tbad\n' >"${BATS_TEST_TMPDIR}/rules.list"
  run owp_firewall_render "$RULES" "22" "${BATS_TEST_TMPDIR}/rules.list"
  [ "$status" -eq 1 ]
  [[ "${lines[-1]}" == *'"error":"invalid_rule"'* ]]
}
