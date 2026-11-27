#!/usr/bin/env bats
# Tests for the join mode of the installer (E-196).

ROOT="${BATS_TEST_DIRNAME}/.."

setup() {
  set -o pipefail
  # shellcheck source=server/lib/common.sh
  source "${ROOT}/server/lib/common.sh"
  # shellcheck source=server/lib/join.sh
  source "${ROOT}/server/lib/join.sh"
  ok() { printf 'ok %s\n' "$*"; }
  [[ ! -e "$JOIN_FW_PENDING" ]] || skip "a firewall change waits on this machine"
}

@test "a confirmed firewall counts as confirmed, also under pipefail" {
  nft() {
    printf 'table inet owpanel {\n  ip saddr 10.0.0.0/16 accept comment "OWPanel private network"\n'
    sleep 0.2
    printf '  counter packets 0 bytes 0 comment "OWPanel dropped"\n}\n'
  }
  JOIN_PRIVATE_IP="10.0.0.3"
  run join_wait_for_firewall
  [ "$status" -eq 0 ]
  [[ "$output" == *"firewall confirmed by the panel"* ]]
}

@test "a rolled back firewall is reported" {
  nft() { printf 'table inet owpanel {\n  tcp dport 22 accept\n}\n'; }
  JOIN_PRIVATE_IP="10.0.0.3"
  run join_wait_for_firewall
  [ "$status" -ne 0 ]
  [[ "$output" == *"firewall_not_confirmed"* ]]
}
