#!/usr/bin/env bats
# Tests for server/bin/site-logs with fixture logs.

ROOT="${BATS_TEST_DIRNAME}/.."

setup() {
  command -v php >/dev/null || skip "PHP is not installed"
  SCRIPT="${BATS_TEST_TMPDIR}/site-logs"
  sed "s#/opt/owpanel/lib/#${ROOT}/server/lib/#; s#\${OWP_ROOT}/lib/#${ROOT}/server/lib/#" "${ROOT}/server/bin/site-logs" >"$SCRIPT"
  export OWP_NGINX_LOGS="${BATS_TEST_TMPDIR}/nginx"
  export OWP_PHP_BIN="$(command -v php)"
  mkdir -p "$OWP_NGINX_LOGS"
  for i in $(seq 1 300); do
    printf '203.0.113.%s - - [11/Nov/2026:12:00:00 +0100] "GET /seite-%s HTTP/2.0" 200 512 "-" "Browser \\"quoted\\""\n' "$((i % 250))" "$i"
  done >"${OWP_NGINX_LOGS}/ow38217.access.log"
  {
    printf '2026/11/11 12:00:01 [error] 1#1: *1 open() "/var/www/ow38217/htdocs/public/favicon.ico" failed (2: No such file or directory)\n'
    printf '2026/11/11 12:00:02 [error] 1#1: *2 FastCGI sent in stderr: "PHP message: PHP Warning:  Undefined variable $x in /var/www/ow38217/htdocs/public/index.php on line 3"\n'
    printf '2026/11/11 12:00:03 [error] 1#1: *3 FastCGI sent in stderr: "PHP message: PHP Fatal error:  \xff\xfe broken"\n'
  } >"${OWP_NGINX_LOGS}/ow38217.error.log"
}

json() {
  php -r '$d = json_decode(stream_get_contents(STDIN), true); echo json_encode(eval("return ".$argv[1].";"), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);' "$1" <<<"${lines[-1]}"
}

@test "site-logs shows the last lines of the access log" {
  run bash "$SCRIPT" --user ow38217 --log access --lines 5
  [ "$status" -eq 0 ]
  [ "$(json 'count($d["lines"])')" = "5" ]
  [[ "$(json '$d["lines"][4]')" == *'GET /seite-300 HTTP/2.0'*'quoted'* ]]
  [ "$(json '$d["ok"]')" = "true" ]
}

@test "site-logs filters PHP messages and replaces broken bytes" {
  run bash "$SCRIPT" --user ow38217 --log php
  [ "$status" -eq 0 ]
  [ "$(json 'count($d["lines"])')" = "2" ]
  [[ "$(json '$d["lines"][1]')" == *'PHP Fatal error'* ]]

  run bash "$SCRIPT" --user ow38217 --log error
  [ "$(json 'count($d["lines"])')" = "3" ]
}

@test "site-logs answers with an empty list for a missing log" {
  run bash "$SCRIPT" --user ow40000 --log access
  [ "$status" -eq 0 ]
  [ "$(json '$d["lines"]')" = "[]" ]
  [ "$(json '$d["modified"]')" = "null" ]
}

@test "site-logs checks its arguments" {
  run bash "$SCRIPT" --user root --log access
  [[ "${lines[-1]}" == *'"error":"invalid_user"'* ]]
  run bash "$SCRIPT" --user ow38217 --log ../../etc/shadow
  [[ "${lines[-1]}" == *'"error":"invalid_arguments"'* ]]
  for count in 0 1001 abc; do
    run bash "$SCRIPT" --user ow38217 --log access --lines "$count"
    [[ "${lines[-1]}" == *'"error":"invalid_arguments"'* ]]
  done
}

@test "site-logs reads the cron output from the journal" {
  export OWP_JOURNALCTL="${BATS_TEST_TMPDIR}/journalctl"
  printf '#!/usr/bin/env bash\nprintf "journal %%s\\n" "$*"\n' >"$OWP_JOURNALCTL"
  chmod +x "$OWP_JOURNALCTL"
  run bash "$SCRIPT" --user ow38217 --log cron --lines 50
  [ "$status" -eq 0 ]
  [[ "$(json '$d["lines"][0]')" == *'--lines=50 SYSLOG_IDENTIFIER=owpanel-cron-ow38217'* ]]
  [ "$(json '$d["size"]')" = "0" ]
}
