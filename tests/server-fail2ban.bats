#!/usr/bin/env bats
# Tests for server/bin/server-fail2ban with a fake fail2ban-client.

ROOT="${BATS_TEST_DIRNAME}/.."

setup() {
  SCRIPT="${BATS_TEST_TMPDIR}/server-fail2ban"
  sed "s#/opt/owpanel/lib/#${ROOT}/server/lib/#" "${ROOT}/server/bin/server-fail2ban" >"$SCRIPT"
  export OWP_F2B_CLIENT="${BATS_TEST_TMPDIR}/fail2ban-client"
  export OWP_F2B_IGNORE="${BATS_TEST_TMPDIR}/owpanel-ignore.local"
  export CALLS="${BATS_TEST_TMPDIR}/calls"
  cat >"$OWP_F2B_CLIENT" <<'FAKE'
#!/usr/bin/env bash
printf '%s\n' "$*" >>"$CALLS"
case "$*" in
  status) printf 'Status\n|- Number of jail:\t2\n`- Jail list:\tsshd, recidive\n' ;;
  "get sshd banip --with-time")
    printf '203.0.113.7 \t2026-11-11 17:50:58 + 3600 = 2026-11-11 18:50:58\n'
    printf '2001:db8::5 \t2026-11-11 17:51:00 + 3600 = 2026-11-11 18:51:00\n'
    printf 'bad"value \t2026-11-11 17:51:00 + 3600 = 2026-11-11 18:51:00\n' ;;
  "get recidive banip --with-time") ;;
  unban*) echo 1 ;;
esac
FAKE
  chmod +x "$OWP_F2B_CLIENT"
}

@test "status lists banned addresses with their end and skips odd lines" {
  run bash "$SCRIPT" --action status
  [ "$status" -eq 0 ]
  [ "${lines[-1]}" = '{"ok":true,"script":"server-fail2ban","banned":[{"jail":"sshd","ip":"203.0.113.7","until":"2026-11-11 18:50:58"},{"jail":"sshd","ip":"2001:db8::5","until":"2026-11-11 18:51:00"}],"ignore":[]}' ]
}

@test "status shows the exceptions written by the panel" {
  printf '[DEFAULT]\nignoreip = 127.0.0.1/8 ::1 198.51.100.20 2001:db8::/64\n' >"$OWP_F2B_IGNORE"
  run bash "$SCRIPT" --action status
  [[ "${lines[-1]}" == *'"ignore":["198.51.100.20","2001:db8::/64"]}' ]]
}

@test "status works with an exception file without own addresses" {
  # After the last exception was deleted, grep finds nothing and must not
  # end the script through pipefail.
  printf '[DEFAULT]\nignoreip = 127.0.0.1/8 ::1\n' >"$OWP_F2B_IGNORE"
  run bash "$SCRIPT" --action status
  [ "$status" -eq 0 ]
  [[ "${lines[-1]}" == *'"ignore":[]}' ]]
}

@test "unban frees an address in all jails" {
  run bash "$SCRIPT" --action unban --ip 203.0.113.7
  [ "$status" -eq 0 ]
  [ "${lines[-1]}" = '{"ok":true,"script":"server-fail2ban","unbanned":"203.0.113.7"}' ]
  grep -qx 'unban 203.0.113.7' "$CALLS"
}

@test "invalid addresses and arguments are refused" {
  local ip
  for ip in '1.2.3.4; reboot' '--all' 'example.de' '1.2.3' 'abc'; do
    run bash "$SCRIPT" --action unban --ip "$ip"
    [[ "${lines[-1]}" == *'"error":"invalid_address"'* ]] || { echo "accepted: $ip"; return 1; }
  done
  run bash -c "printf '1.2.3.4\n1.2.3.4 5.6.7.8\n' | bash '$SCRIPT' --action ignore"
  [[ "${lines[-1]}" == *'"error":"invalid_address"'* ]]
  run bash "$SCRIPT" --action ban --ip 1.2.3.4
  [[ "${lines[-1]}" == *'"error":"invalid_arguments"'* ]]
  run bash "$SCRIPT" --action status --ip 1.2.3.4
  [[ "${lines[-1]}" == *'"error":"invalid_arguments"'* ]]
  [ ! -e "$OWP_F2B_IGNORE" ]
}
