#!/usr/bin/env bats
# Tests for server/bin/site-nginx and server/lib/site-rules.sh (E-184):
# checking and writing the own rules of a website. Loading them into nginx
# needs root and nginx and is tested on a VM.

ROOT="${BATS_TEST_DIRNAME}/.."

setup() {
  SCRIPT="${BATS_TEST_TMPDIR}/site-nginx"
  sed "s#/opt/owpanel/lib/#${ROOT}/server/lib/#" "${ROOT}/server/bin/site-nginx" >"$SCRIPT"
  export OWP_NGINX_SITES="${BATS_TEST_TMPDIR}/sites"
  export OWP_SITE_RULES_DIR="${BATS_TEST_TMPDIR}/rules"
  mkdir -p "$OWP_NGINX_SITES"
}

# Renders rules from the given lines into headers and paths.
render() {
  source "${ROOT}/server/lib/common.sh"
  source "${ROOT}/server/lib/site-rules.sh"
  printf '%b' "$1" >"${BATS_TEST_TMPDIR}/in"
  owp_rules_render "${BATS_TEST_TMPDIR}/in" "${BATS_TEST_TMPDIR}/headers" "${BATS_TEST_TMPDIR}/paths"
}

@test "site-nginx checks the user before anything else" {
  run bash "$SCRIPT" </dev/null
  [[ "${lines[-1]}" == *'"error":"invalid_user"'* ]]
  run bash "$SCRIPT" --user root </dev/null
  [[ "${lines[-1]}" == *'"error":"invalid_user"'* ]]
  run bash "$SCRIPT" --user ow10000 </dev/null
  [[ "${lines[-1]}" == *'"error":"reserved_user"'* ]]
  run bash "$SCRIPT" --user ow38217 --force </dev/null
  [[ "${lines[-1]}" == *'"error":"invalid_arguments"'* ]]
}

@test "site-nginx checks the rules before it looks for the website" {
  run bash -c "printf 'header\tX-Test\t\$host\n' | bash '$SCRIPT' --user ow38217"
  [ "$status" -eq 1 ]
  [[ "${lines[-1]}" == *'"error":"invalid_header"'* ]]
  run bash "$SCRIPT" --user ow38217 </dev/null
  [[ "${lines[-1]}" == *'"error":"site_not_found"'* ]]
  [ ! -e "$OWP_SITE_RULES_DIR" ]
}

@test "headers become add_header lines with always" {
  render 'header\tStrict-Transport-Security\tmax-age=31536000; includeSubDomains; preload\nheader\tContent-Security-Policy-Report-Only\tdefault-src '"'"'self'"'"'; img-src '"'"'self'"'"' data:\n'
  run cat "${BATS_TEST_TMPDIR}/headers"
  [[ "$output" == *'add_header Strict-Transport-Security "max-age=31536000; includeSubDomains; preload" always;'* ]]
  [[ "$output" == *"add_header Content-Security-Policy-Report-Only \"default-src 'self'; img-src 'self' data:\" always;"* ]]
  [ "$OWP_RULES_HEADERS" -eq 2 ]
  [ "$OWP_RULES_PATHS" -eq 0 ]
}

@test "paths ending in / block a folder, others exactly one file" {
  render 'path\t/vendor/\npath\t/composer.json\npath\t/support/ticket-errors.log\n'
  run cat "${BATS_TEST_TMPDIR}/paths"
  [[ "$output" == *$'location ^~ /vendor/ {\n    return 404;\n}'* ]]
  [[ "$output" == *$'location = /composer.json {\n    return 404;\n}'* ]]
  [[ "$output" == *'location = /support/ticket-errors.log {'* ]]
  [ "$OWP_RULES_PATHS" -eq 3 ]
}

@test "no rules give files with only the head line" {
  render ''
  [ "$(cat "${BATS_TEST_TMPDIR}/headers")" = '# Managed by OWPanel. Do not edit, changes are overwritten.' ]
  [ "$(cat "${BATS_TEST_TMPDIR}/paths")" = '# Managed by OWPanel. Do not edit, changes are overwritten.' ]
}

@test "header names OWPanel sets or that break responses are refused" {
  local name
  for name in X-Frame-Options referrer-policy Alt-Svc Content-Length Set-Cookie Location 'X Bad' '1X' 'X_Under' ''; do
    run render "header\t${name}\tvalue\n"
    [ "$status" -eq 1 ]
    [[ "${lines[-1]}" == *'"error":"invalid_header"'* ]] || { echo "accepted: $name"; return 1; }
  done
}

@test "header values cannot reach nginx syntax or variables" {
  local value
  for value in '$host' 'a"b' 'a\\b' 'a{b' 'a}b' ' lead' 'trail ' 'tab\there' 'umlaut ä' ''; do
    run render "header\tX-Test\t${value}\n"
    [ "$status" -eq 1 ]
    [[ "${lines[-1]}" == *'"error":"invalid_header"'* ]] || { echo "accepted: $value"; return 1; }
  done
  run render "header\tX-Test\t$(printf 'a%.0s' {1..2049})\n"
  [[ "${lines[-1]}" == *'"error":"invalid_header"'* ]]
}

@test "paths cannot reach other locations or nginx syntax" {
  local path
  for path in / vendor/ '/a b' '/a;b' '/a{b' '//x' '/x//y' '/.git' '/a/.env' '/../etc/' '/.well-known/' '/a$b' '/ä'; do
    run render "path\t${path}\n"
    [ "$status" -eq 1 ]
    [[ "${lines[-1]}" == *'"error":"invalid_path"'* ]] || { echo "accepted: $path"; return 1; }
  done
}

@test "duplicates, unknown kinds and too many rules are refused" {
  run render 'header\tX-Test\ta\nheader\tx-test\tb\n'
  [[ "${lines[-1]}" == *'"error":"duplicate_header"'* ]]
  run render 'path\t/vendor/\npath\t/vendor/\n'
  [[ "${lines[-1]}" == *'"error":"duplicate_path"'* ]]
  run render 'include\t/etc/passwd\n'
  [[ "${lines[-1]}" == *'"error":"invalid_rule"'* ]]
  run render 'path /vendor/\n'
  [[ "${lines[-1]}" == *'"error":"invalid_rule"'* ]]
  local input="" i
  for i in $(seq 1 51); do input+="path\t/p${i}\n"; done
  run render "$input"
  [[ "${lines[-1]}" == *'"error":"too_many_rules"'* ]]
}

@test "site templates include the rules of the website" {
  source "${ROOT}/server/lib/common.sh"
  source "${ROOT}/server/lib/site.sh"
  local site="${BATS_TEST_TMPDIR}/site.conf"
  owp_site_backend "${ROOT}/server/templates/nginx" "$site" example.de ow38217 /var/www/ow38217 72 90 127.0.0.1 127.0.0.1
  # Every include of the OWPanel headers is followed by the own headers.
  [ "$(grep -c 'include /etc/nginx/owpanel/backend-headers.conf;' "$site")" -eq "$(grep -c 'include /etc/nginx/owpanel-site-rules/ow38217-headers.conf;' "$site")" ]
  run awk '/owpanel\/backend-headers.conf;/ { want = 1; next } want { if ($0 !~ /ow38217-headers.conf;/) bad = 1; want = 0 } END { exit bad }' "$site"
  [ "$status" -eq 0 ]
  [ "$(grep -c 'include /etc/nginx/owpanel-site-rules/ow38217-paths.conf;' "$site")" -eq 1 ]

  # The gateway passes them through and has none of its own (E-195), so
  # it also works where the rules files are not.
  owp_site_gateway "${ROOT}/server/templates/nginx" "$site" https example.de ow38217 1 1 72 90 01abc
  ! grep -q 'owpanel-site-rules' "$site"
}

@test "site-create creates the rule files and site-delete removes them" {
  grep -q 'owp_rules_ensure "$user"' "${ROOT}/server/bin/site-create"
  grep -q 'owp_rules_remove "$user"' "${ROOT}/server/bin/site-delete"
  source "${ROOT}/server/lib/common.sh"
  source "${ROOT}/server/lib/site-rules.sh"
  mkdir -p "$OWP_SITE_RULES_DIR"
  printf 'old\n' >"${OWP_SITE_RULES_DIR}/ow38217-paths.conf"
  owp_rules_ensure ow38217
  # A missing file is created empty, existing rules stay.
  [ "$(cat "${OWP_SITE_RULES_DIR}/ow38217-headers.conf")" = "$OWP_RULES_HEAD" ]
  [ "$(cat "${OWP_SITE_RULES_DIR}/ow38217-paths.conf")" = old ]
  owp_rules_remove ow38217
  [ ! -e "${OWP_SITE_RULES_DIR}/ow38217-headers.conf" ]
  [ ! -e "${OWP_SITE_RULES_DIR}/ow38217-paths.conf" ]
}
