#!/usr/bin/env bats
# Tests for server/bin/site-stats with fixture logs (E-148).

ROOT="${BATS_TEST_DIRNAME}/.."

setup() {
  command -v php >/dev/null || skip "PHP is not installed"
  SCRIPT="${BATS_TEST_TMPDIR}/site-stats"
  sed "s#/opt/owpanel/lib/#${ROOT}/server/lib/#; s#\${OWP_ROOT}/lib/#${ROOT}/server/lib/#" "${ROOT}/server/bin/site-stats" >"$SCRIPT"
  export OWP_NGINX_LOGS="${BATS_TEST_TMPDIR}/nginx"
  export OWP_PHP_BIN="$(command -v php)"
  mkdir -p "$OWP_NGINX_LOGS"
  local today yesterday old base="${OWP_NGINX_LOGS}/ow38217.access.log"
  today="$(date +%d/%b/%Y)"
  yesterday="$(date -d yesterday +%d/%b/%Y)"
  old="$(date -d '5 days ago' +%d/%b/%Y)"
  printf '203.0.113.1 - - [%s:10:00:00 +0100] "GET /alt HTTP/2.0" 200 5 "-" "Mozilla/5.0"\n' "$old" | gzip >"${base}.3.gz"
  {
    printf '203.0.113.1 - - [%s:10:00:00 +0100] "GET / HTTP/2.0" 200 5 "-" "Mozilla/5.0 (iPhone)"\n' "$yesterday"
    printf '203.0.113.2 - - [%s:10:00:00 +0100] "GET /kontakt?x=1 HTTP/2.0" 200 5 "-" "Mozilla/5.0"\n' "$yesterday"
  } >"${base}.1"
  {
    printf '203.0.113.1 - - [%s:09:00:00 +0100] "GET / HTTP/2.0" 200 5 "-" "Mozilla/5.0 (iPhone)"\n' "$today"
    printf '203.0.113.1 - - [%s:09:00:01 +0100] "GET /style.css HTTP/2.0" 200 5 "-" "Mozilla/5.0 (iPhone)"\n' "$today"
    printf '203.0.113.1 - - [%s:09:00:02 +0100] "GET /%%C3%%BCber-uns HTTP/2.0" 304 0 "-" "Mozilla/5.0 (iPhone)"\n' "$today"
    printf '203.0.113.3 - - [%s:09:00:03 +0100] "GET / HTTP/2.0" 200 5 "-" "Googlebot/2.1"\n' "$today"
    printf '203.0.113.4 - - [%s:09:00:04 +0100] "POST /login HTTP/2.0" 200 5 "-" "Mozilla/5.0"\n' "$today"
    printf '203.0.113.5 - - [%s:09:00:05 +0100] "GET /fehlt HTTP/2.0" 404 5 "-" "Mozilla/5.0"\n' "$today"
    printf '203.0.113.7 - - [%s:09:00:06 +0100] "GET /alt HTTP/2.0" 301 5 "-" "Mozilla/5.0"\n' "$today"
    printf '203.0.113.6 - - [%s:09:00:07 +0100] "GET / HTTP/2.0" 200 5 "-" "Mozilla \\"quoted\\""\n' "$today"
    printf 'not a log line\n'
  } >"$base"
}

json() {
  php -r '$d = json_decode(stream_get_contents(STDIN), true); echo json_encode(eval("return ".$argv[1].";"), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);' "$1" <<<"${lines[-1]}"
}

@test "site-stats counts visitors and page views per day, without assets, bots, errors and redirects" {
  run bash "$SCRIPT" --user ow38217 --days 7
  [ "$status" -eq 0 ]
  [ "$(json 'count($d["days"])')" = "7" ]
  [ "$(json '$d["days"][6]["visitors"]')" = "2" ]
  [ "$(json '$d["days"][6]["pageviews"]')" = "3" ]
  [ "$(json '$d["days"][6]["pages"][0]')" = '["/",2]' ]
  [ "$(json '$d["days"][6]["pages"][1]')" = '["/über-uns",1]' ]
  [ "$(json '$d["days"][5]["pages"][1]')" = '["/kontakt",1]' ]
  [ "$(json '$d["days"][1]["visitors"]')" = "1" ]
  [ "$(json '$d["days"][0]["pageviews"]')" = "0" ]
}

@test "site-stats never answers with addresses or user agents" {
  run bash "$SCRIPT" --user ow38217 --days 14
  [ "$status" -eq 0 ]
  [[ "${lines[-1]}" != *'203.0.113'* ]]
  [[ "${lines[-1]}" != *'Mozilla'* ]]
  [[ "${lines[-1]}" != *'iPhone'* ]]
}

@test "site-stats answers with empty days for a website without log" {
  run bash "$SCRIPT" --user ow40000 --days 2
  [ "$status" -eq 0 ]
  [ "$(json '$d["days"][1]["visitors"]')" = "0" ]
}

@test "site-stats checks its arguments" {
  run bash "$SCRIPT" --user root
  [[ "${lines[-1]}" == *'"error":"invalid_user"'* ]]
  for days in 0 15 abc -1; do
    run bash "$SCRIPT" --user ow38217 --days "$days"
    [[ "${lines[-1]}" == *'"error":"invalid_arguments"'* ]]
  done
}
