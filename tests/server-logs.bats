#!/usr/bin/env bats
# Tests for server/bin/server-logs with fixtures and a fake journalctl.

ROOT="${BATS_TEST_DIRNAME}/.."

setup() {
  command -v php >/dev/null || skip "PHP is not installed"
  SCRIPT="${BATS_TEST_TMPDIR}/server-logs"
  sed "s#/opt/owpanel/lib/#${ROOT}/server/lib/#; s#\${OWP_ROOT}/lib/#${ROOT}/server/lib/#" "${ROOT}/server/bin/server-logs" >"$SCRIPT"
  export OWP_NGINX_LOGS="${BATS_TEST_TMPDIR}/nginx"
  export OWP_FAIL2BAN_LOG="${BATS_TEST_TMPDIR}/fail2ban.log"
  export OWP_PHP_BIN="$(command -v php)"
  export OWP_JOURNALCTL="${BATS_TEST_TMPDIR}/journalctl"
  mkdir -p "$OWP_NGINX_LOGS"
  # The fake journal prints its arguments, so the tests see the filter.
  cat >"$OWP_JOURNALCTL" <<'FAKE'
#!/usr/bin/env bash
printf 'journal %s\n' "$*"
FAKE
  chmod +x "$OWP_JOURNALCTL"
  printf '2026/11/11 12:00:00 [warn] 1#1: conflicting server name\n' >"${OWP_NGINX_LOGS}/error.log"
}

json() {
  php -r '$d = json_decode(stream_get_contents(STDIN), true); echo json_encode(eval("return ".$argv[1].";"), JSON_UNESCAPED_SLASHES);' "$1" <<<"${lines[-1]}"
}

@test "auth and system read the journal" {
  run bash "$SCRIPT" --log auth --lines 50
  [ "$status" -eq 0 ]
  [[ "$(json '$d["lines"][0]')" == *'--lines=50 SYSLOG_FACILITY=4 SYSLOG_FACILITY=10'* ]]

  run bash "$SCRIPT" --log system
  [[ "$(json '$d["lines"][0]')" == *'--lines=200"' ]]
}

@test "fail2ban reads its log file and falls back to the journal" {
  printf '2026-11-11 12:00:00,000 fail2ban.actions [1]: NOTICE [sshd] Ban 203.0.113.5\n' >"$OWP_FAIL2BAN_LOG"
  run bash "$SCRIPT" --log fail2ban
  [[ "$(json '$d["lines"][0]')" == *'Ban 203.0.113.5'* ]]

  rm "$OWP_FAIL2BAN_LOG"
  run bash "$SCRIPT" --log fail2ban
  [[ "$(json '$d["lines"][0]')" == *'--unit=fail2ban.service'* ]]
}

@test "nginx reads the global error log" {
  run bash "$SCRIPT" --log nginx
  [[ "$(json '$d["lines"][0]')" == *'conflicting server name'* ]]
}

@test "server-logs takes no file names and checks the line count" {
  run bash "$SCRIPT" --log /etc/shadow
  [[ "${lines[-1]}" == *'"error":"invalid_arguments"'* ]]
  run bash "$SCRIPT" --log auth --lines 5000
  [[ "${lines[-1]}" == *'"error":"invalid_arguments"'* ]]
  run bash "$SCRIPT" --log auth --file /etc/shadow
  [[ "${lines[-1]}" == *'"error":"invalid_arguments"'* ]]
}
