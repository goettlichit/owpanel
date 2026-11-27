#!/usr/bin/env bats
# Tests for the website helpers. Runs without root.

ROOT="${BATS_TEST_DIRNAME}/.."

setup() {
  # shellcheck source=server/lib/common.sh
  source "${ROOT}/server/lib/common.sh"
  # shellcheck source=server/lib/site.sh
  source "${ROOT}/server/lib/site.sh"
}

@test "accepts valid domains" {
  local domain
  for domain in example.de www.example.de test.sub.example.co.uk a1.de \
    xn--mnchen-3ya.de example.xn--p1ai my-site.example.com; do
    owp_valid_domain "$domain" || { echo "rejected: $domain"; return 1; }
  done
}

@test "rejects manipulated domains" {
  local domain long_label
  long_label="$(printf 'a%.0s' {1..64})"
  for domain in "" de localhost Example.de example.de. .example.de "a..de" \
    -example.de example-.de "exa_mple.de" "exa mple.de" "example.de;id" \
    'example.de$(id)' "../etc.de" "example.de/x" "192.168.1.1" "1.2.3.4" \
    "example.1" "${long_label}.de" $'example.de\nevil.de' "*.example.de" \
    "example.de:80" "exämple.de"; do
    if owp_valid_domain "$domain"; then
      echo "accepted: $domain"
      return 1
    fi
  done
}

@test "rejects domains longer than 253 characters" {
  local label domain=""
  label="$(printf 'a%.0s' {1..60})"
  domain="${label}.${label}.${label}.${label}.${label}.de"
  [ "${#domain}" -gt 253 ]
  ! owp_valid_domain "$domain"
}

@test "accepts site users ow10000 to ow59999" {
  local user
  for user in ow10000 ow10001 ow10042 ow49999 ow59999; do
    owp_valid_site_user "$user" || { echo "rejected: $user"; return 1; }
  done
}

@test "rejects other user names" {
  local user
  for user in "" ow ow0 ow1 ow1042 ow9999 ow09999 ow010042 ow60000 ow99999 ow100000 \
    root www-data OW10001 "ow10001 " " ow10001" "ow10001;id" 'ow$(id)' ow-10001 ow10001a \
    s-example-de-12345678 $'ow10001\nroot'; do
    if owp_valid_site_user "$user"; then
      echo "accepted: $user"
      return 1
    fi
  done
}

@test "reserves ow10000 for the panel" {
  owp_reserved_site_user ow10000
  ! owp_reserved_site_user ow10001
  ! owp_reserved_site_user ow38217
}

@test "uses the number of the site user as UID" {
  [ "$(owp_site_uid ow10000)" = 10000 ]
  [ "$(owp_site_uid ow10042)" = 10042 ]
  [ "$(owp_site_uid ow59999)" = 59999 ]
}

@test "renders all site templates without leftover placeholders" {
  local template
  for template in "${ROOT}"/server/templates/nginx/site-*.conf "${ROOT}"/server/templates/nginx/gateway-*.conf \
    "${ROOT}/server/templates/nginx/backend-default.conf" "${ROOT}/server/templates/php/site-pool.conf"; do
    owp_render "$template" "${BATS_TEST_TMPDIR}/out" DOMAIN=example.de \
      USER=ow10042 ROOT=/var/www/ow10042 SCHEME=https NAMES='example.de www.example.de' \
      BODY=72 TIMEOUT=90 PROCESSES=10 MEMORY=256 UPLOAD=64 POST=72 TIME=60 TERMINATE=90 ERRORS=off SECURE=off \
      LISTEN=127.0.0.1 GATEWAY=127.0.0.1 BACKEND=01abc ADDRESS=127.0.0.1
    ! grep -q '@[A-Z_]*@' "${BATS_TEST_TMPDIR}/out"
    ! grep -q 'example\.de' <(grep -E 'root |_log |ssl_certificate|open_basedir|listen = ' "${BATS_TEST_TMPDIR}/out")
  done
  grep -q 'listen = /run/php/owpanel-ow10042.sock' "${BATS_TEST_TMPDIR}/out"
}

@test "keeps nginx variables and special characters literal" {
  printf 'a $uri @X@ b\n' >"${BATS_TEST_TMPDIR}/tpl"
  owp_render "${BATS_TEST_TMPDIR}/tpl" "${BATS_TEST_TMPDIR}/out" 'X=1&2\3'
  [ "$(cat "${BATS_TEST_TMPDIR}/out")" = 'a $uri 1&2\3 b' ]
}

@test "fails on missing placeholder values" {
  printf '@DOMAIN@ @USER@\n' >"${BATS_TEST_TMPDIR}/tpl"
  run owp_render "${BATS_TEST_TMPDIR}/tpl" "${BATS_TEST_TMPDIR}/out" DOMAIN=example.de
  [ "$status" -eq 1 ]
  [[ "$output" == *"@USER@"* ]]
}

@test "result line can carry extra fields" {
  OWP_SCRIPT_NAME="site-create"
  run owp_result_ok '"tls":false'
  [ "$output" = '{"ok":true,"script":"site-create","tls":false}' ]
}

@test "site-delete validates its parameters before anything else" {
  # The script sources /opt/owpanel/lib, so it can only run after setup-agent.
  [ -r /opt/owpanel/lib/site.sh ] || skip "OWPanel is not installed here"
  local args
  for args in "" "--user" "--user ow1042" "--user ow60000" "--user root" "--user ../ow10001" "--domain x.de" "--bogus x"; do
    # shellcheck disable=SC2086  # each entry is a parameter list
    run "${ROOT}/server/bin/site-delete" $args
    [ "$status" -eq 1 ]
    [[ "${lines[-1]}" == *'"error":"invalid_'* ]] || { echo "$args: ${lines[-1]}"; return 1; }
  done
  run "${ROOT}/server/bin/site-delete" --user ow10000
  [ "$status" -eq 1 ]
  [ "${lines[-1]}" = '{"ok":false,"script":"site-delete","error":"reserved_user"}' ]
}

render_https() {
  owp_render "${ROOT}/server/templates/nginx/gateway-site-https.conf" "${BATS_TEST_TMPDIR}/https.conf" \
    DOMAIN=example.de USER=ow38217 BODY=72 TIMEOUT=90 BACKEND=01abc
}

@test "HTTPS sites listen for HTTP/3 without reuseport" {
  render_https
  grep -q '^    listen 443 quic;$' "${BATS_TEST_TMPDIR}/https.conf"
  grep -q '^    listen \[::\]:443 quic;$' "${BATS_TEST_TMPDIR}/https.conf"
  grep -q '^    http3 on;$' "${BATS_TEST_TMPDIR}/https.conf"
  ! grep -q reuseport "${BATS_TEST_TMPDIR}/https.conf"
}

@test "the gateway announces HTTP/3 for the TLS block, the backend sets the other headers (E-195)" {
  render_https
  [ "$(grep -c 'include /etc/nginx/owpanel/gateway-headers.conf;' "${BATS_TEST_TMPDIR}/https.conf")" -eq 1 ]
  ! grep -q 'add_header' "${BATS_TEST_TMPDIR}/https.conf"
  grep -qF "add_header Alt-Svc 'h3=\":443\"; ma=86400' always;" "${ROOT}/server/templates/nginx/gateway-headers.conf"
  ! grep -q 'Alt-Svc' "${ROOT}/server/templates/nginx/backend-headers.conf"
  owp_site_backend "${ROOT}/server/templates/nginx" "${BATS_TEST_TMPDIR}/backend.conf" example.de ow38217 /var/www/ow38217 72 90 127.0.0.1 127.0.0.1
  local blocks includes
  # The server block and its six location blocks (placeholder page, E-171;
  # static files, E-176).
  blocks="$(awk '/listen 127.0.0.1:8443 ssl;/{s=1} s && /^    location /{n++} END{print n+1}' "${BATS_TEST_TMPDIR}/backend.conf")"
  includes="$(grep -c 'include /etc/nginx/owpanel/backend-headers.conf;' "${BATS_TEST_TMPDIR}/backend.conf")"
  [ "$blocks" -eq 7 ]
  [ "$includes" -eq "$blocks" ]
}

@test "the header snippet advertises HTTP/3 and leaves out HSTS" {
  grep -qF "add_header Alt-Svc 'h3=\":443\"; ma=86400' always;" "${ROOT}/server/templates/nginx/headers.conf"
  ! grep -qi 'strict-transport-security' "${ROOT}/server/templates/nginx/headers.conf"
}

@test "only the default server sets reuseport, for IPv4 and IPv6" {
  local file="${ROOT}/server/templates/nginx/owpanel-default.conf"
  [ "$(grep -c 'quic reuseport default_server;' "$file")" -eq 2 ]
  grep -q 'ssl_reject_handshake on;' "$file"
  [ "$(grep -rl reuseport "${ROOT}/server/templates/nginx")" = "$file" ]
}

@test "0-RTT stays off and the QUIC key is fixed" {
  grep -qx 'ssl_early_data off;' "${ROOT}/server/templates/nginx/owpanel-quic.conf"
  grep -qx 'quic_host_key /etc/owpanel/quic_host.key;' "${ROOT}/server/templates/nginx/owpanel-quic.conf"
}

render_site() {
  owp_site_gateway "${ROOT}/server/templates/nginx" "${BATS_TEST_TMPDIR}/site.conf" "$1" example.de ow38217 "$2" "$3" 72 90 01abc
}

@test "without www a site answers to its domain only" {
  render_site https 0 0
  [ "$(owp_server_names "${BATS_TEST_TMPDIR}/site.conf" | sort -u)" = "example.de" ]
  ! grep -q 'www\.' "${BATS_TEST_TMPDIR}/site.conf"
}

@test "with www and a certificate for both, www sends everything to the main address" {
  render_site https 1 1
  [ "$(owp_server_names "${BATS_TEST_TMPDIR}/site.conf" | sort -u | tr '\n' ' ')" = "example.de www.example.de " ]
  # Port 80 for Let's Encrypt and HTTPS with HTTP/3, both redirecting.
  [ "$(grep -c 'server_name www.example.de;' "${BATS_TEST_TMPDIR}/site.conf")" -eq 2 ]
  [ "$(grep -c 'return 301 https://example.de$request_uri;' "${BATS_TEST_TMPDIR}/site.conf")" -eq 3 ]
  grep -q 'root /var/lib/owpanel/acme;' "${BATS_TEST_TMPDIR}/site.conf"
  [ "$(grep -c '^    listen 443 quic;$' "${BATS_TEST_TMPDIR}/site.conf")" -eq 2 ]
  [ "$(grep -c 'ssl_certificate /etc/letsencrypt/live/ow38217/fullchain.pem;' "${BATS_TEST_TMPDIR}/site.conf")" -eq 2 ]
  ! grep -q '@[A-Z_]*@' "${BATS_TEST_TMPDIR}/site.conf"
}

@test "with www but no certificate for it, www only answers on port 80" {
  render_site https 1 0
  [ "$(grep -c 'server_name www.example.de;' "${BATS_TEST_TMPDIR}/site.conf")" -eq 1 ]
  [ "$(grep -c 'ssl_certificate ' "${BATS_TEST_TMPDIR}/site.conf")" -eq 1 ]
  grep -q 'return 301 https://example.de$request_uri;' "${BATS_TEST_TMPDIR}/site.conf"
}

@test "an HTTP site with www sends www to the main address over HTTP" {
  render_site http 1 1
  [ "$(grep -c 'server_name www.example.de;' "${BATS_TEST_TMPDIR}/site.conf")" -eq 1 ]
  grep -q 'return 301 http://example.de$request_uri;' "${BATS_TEST_TMPDIR}/site.conf"
  ! grep -q 'listen 443' "${BATS_TEST_TMPDIR}/site.conf"
}

@test "server names are read from every block" {
  printf '    server_name a.de;\n  server_name  www.a.de b.de ;\n#server_name x.de;\n' >"${BATS_TEST_TMPDIR}/n.conf"
  [ "$(owp_server_names "${BATS_TEST_TMPDIR}/n.conf" | tr '\n' ' ')" = "a.de www.a.de b.de " ]
}

gateway_site_copy() {
  sed "s#/opt/owpanel/lib/#${ROOT}/server/lib/#" "${ROOT}/server/bin/gateway-site" >"${BATS_TEST_TMPDIR}/gateway-site"
}

@test "gateway-site checks --www" {
  gateway_site_copy
  run bash "${BATS_TEST_TMPDIR}/gateway-site" --domain www.example.de --user ow38217 --backend-id 01abc --backend 127.0.0.1 --www
  [ "$status" -eq 1 ]
  [ "${lines[-1]}" = '{"ok":false,"script":"gateway-site","error":"invalid_arguments"}' ]
}

@test "gateway-site checks backend, body and timeout (E-195)" {
  gateway_site_copy
  local args
  for args in "--backend-id 01ABC --backend 127.0.0.1" "--backend-id 01abc --backend 10.0.0.256" \
    "--backend-id 01abc --backend example.de" "--backend-id 01abc --backend 127.0.0.1 --body 0" \
    "--backend-id 01abc --backend 127.0.0.1 --timeout 99999" "--backend-id '../x' --backend 127.0.0.1"; do
    # shellcheck disable=SC2086
    eval "run bash \"\${BATS_TEST_TMPDIR}/gateway-site\" --domain example.de --user ow38217 $args"
    [ "$status" -eq 1 ] || { echo "accepted: $args"; return 1; }
    [ "${lines[-1]}" = '{"ok":false,"script":"gateway-site","error":"invalid_arguments"}' ]
  done
  run bash "${BATS_TEST_TMPDIR}/gateway-site" --user ow38217 --remove --tls
  [ "${lines[-1]}" = '{"ok":false,"script":"gateway-site","error":"invalid_arguments"}' ]
}

@test "site-create checks --listen and --gateway (E-195)" {
  sed "s#/opt/owpanel/lib/#${ROOT}/server/lib/#" "${ROOT}/server/bin/site-create" >"${BATS_TEST_TMPDIR}/site-create"
  run bash "${BATS_TEST_TMPDIR}/site-create" --domain example.de --type php --user ow38217 --listen 1.2.3
  [ "${lines[-1]}" = '{"ok":false,"script":"site-create","error":"invalid_arguments"}' ]
  run bash "${BATS_TEST_TMPDIR}/site-create" --domain example.de --type php --user ow38217 --gateway '127.0.0.1;'
  [ "${lines[-1]}" = '{"ok":false,"script":"site-create","error":"invalid_arguments"}' ]
  run bash "${BATS_TEST_TMPDIR}/site-create" --domain example.de --type php --user ow38217 --tls
  [ "${lines[-1]}" = '{"ok":false,"script":"site-create","error":"invalid_arguments"}' ]
}

# change_domain without root, nginx and certbot: the copy of gateway-site is
# sourced without running main, and the outside world is stubbed.
change_domain_setup() {
  # The libraries are loaded by setup, the constants are set below.
  # shellcheck source=server/lib/suspend.sh
  source "${ROOT}/server/lib/suspend.sh"
  sed -e '/^main "\$@"$/d' -e '/^source /d' -e '/^readonly /d' -e '/^set -euo pipefail$/d' \
    "${ROOT}/server/bin/gateway-site" >"${BATS_TEST_TMPDIR}/gateway-site-lib"
  # shellcheck disable=SC1091
  source "${BATS_TEST_TMPDIR}/gateway-site-lib"
  NGINX_SITES="${BATS_TEST_TMPDIR}/sites"
  TEMPLATES="${ROOT}/server/templates"
  OWP_TMPDIR="${BATS_TEST_TMPDIR}/tmp"
  mkdir -p "$NGINX_SITES" "$OWP_TMPDIR"
  printf 'server {\n    server_name old.example.de;\n}\n' >"${NGINX_SITES}/ow10042.conf"
  APPLIED="${BATS_TEST_TMPDIR}/applied"
  owp_apply_config() { cp "$1" "$2"; printf '%s\n' "$(owp_server_names "$2" | tr '\n' ' ')" >>"$APPLIED"; }
  needs_certificate() { return 0; }
  owp_warn() { :; }
}

@test "change_domain keeps the old site while the certificate is requested" {
  change_domain_setup
  request_certificate() { printf '%s\n' "$*" >"${BATS_TEST_TMPDIR}/requested"; }
  change_domain ow10042 old.example.de 1 1 new.example.de www.new.example.de
  [ "$(cat "${BATS_TEST_TMPDIR}/requested")" = "ow10042 new.example.de www.new.example.de" ]
  [ "$(cat "$APPLIED")" = "old.example.de new.example.de www.new.example.de " ]
}

@test "change_domain falls back to the domain alone without www" {
  change_domain_setup
  request_certificate() { printf '%s\n' "$*" >>"${BATS_TEST_TMPDIR}/requested"; [ "$#" -eq 2 ]; }
  change_domain ow10042 old.example.de 1 1 new.example.de www.new.example.de
  [ "$(tail -n 1 "${BATS_TEST_TMPDIR}/requested")" = "ow10042 new.example.de" ]
}

@test "change_domain restores the old site if the certificate is refused" {
  change_domain_setup
  request_certificate() { return 1; }
  run change_domain ow10042 old.example.de 1 0 new.example.de
  [ "$status" -eq 1 ]
  [[ "${lines[-1]}" == *'"error":"certificate_failed"'* ]]
  [ "$(tail -n 1 "$APPLIED")" = "old.example.de " ]
  [ "$(owp_server_names "${NGINX_SITES}/ow10042.conf")" = "old.example.de" ]
}

@test "change_domain needs no certificate for a site without one" {
  change_domain_setup
  request_certificate() { return 1; }
  run change_domain ow10042 old.example.de 0 0 new.example.de
  [ "$status" -eq 1 ]
  [ ! -e "$APPLIED" ]
}

@test "change_domain refuses a site that does not answer to the previous domain" {
  change_domain_setup
  run change_domain ow10042 other.example.de 1 0 new.example.de
  [ "$status" -eq 1 ]
  [[ "${lines[-1]}" == *'"error":"domain_mismatch"'* ]]
}

@test "gateway-site checks --previous-domain" {
  gateway_site_copy
  run bash "${BATS_TEST_TMPDIR}/gateway-site" --domain example.de --user ow38217 --backend-id 01abc --backend 127.0.0.1 --previous-domain example.de
  [ "$status" -eq 1 ]
  [ "${lines[-1]}" = '{"ok":false,"script":"gateway-site","error":"invalid_arguments"}' ]
  run bash "${BATS_TEST_TMPDIR}/gateway-site" --domain example.de --user ow38217 --backend-id 01abc --backend 127.0.0.1 --previous-domain 'bad domain'
  [ "$status" -eq 1 ]
  [ "${lines[-1]}" = '{"ok":false,"script":"gateway-site","error":"invalid_domain"}' ]
}

# Functions of site-create, sourced without running main.
site_create_setup() {
  sed -e '/^main "\$@"$/d' -e '/^source /d' -e '/^readonly /d' -e '/^set -euo pipefail$/d' \
    "${ROOT}/server/bin/site-create" >"${BATS_TEST_TMPDIR}/site-create-lib"
  # shellcheck disable=SC1091
  source "${BATS_TEST_TMPDIR}/site-create-lib"
  TEMPLATES="${ROOT}/server/templates"
  OWP_TMPDIR="${BATS_TEST_TMPDIR}/tmp"
  mkdir -p "$OWP_TMPDIR"
  owp_warn() { :; }
}

# The placeholder page of a website (E-171).
welcome_setup() {
  site_create_setup
  WELCOME_OVERRIDE="${BATS_TEST_TMPDIR}/override.html"
  WELCOME_DIR="${BATS_TEST_TMPDIR}/welcome"
  install() {
    local -a args=()
    while [[ $# -gt 0 ]]; do
      case "$1" in -o | -g | -m) shift 2 ;; *) args+=("$1"); shift ;; esac
    done
    command install "${args[@]}"
  }
}

@test "the placeholder page is written for the website with its domain" {
  welcome_setup
  apply_welcome ow10042 shop.example.de
  grep -q '<title>shop.example.de</title>' "${WELCOME_DIR}/ow10042.html"
  ! grep -q '@DOMAIN@' "${WELCOME_DIR}/ow10042.html"
  # A new domain arrives with the next run.
  apply_welcome ow10042 neu.example.de
  grep -q '<title>neu.example.de</title>' "${WELCOME_DIR}/ow10042.html"
}

@test "an own placeholder page of the administrator is used, a broken one is not" {
  welcome_setup
  printf '<h1>Bald da: @DOMAIN@</h1>\n' >"$WELCOME_OVERRIDE"
  apply_welcome ow10042 shop.example.de
  [ "$(cat "${WELCOME_DIR}/ow10042.html")" = "<h1>Bald da: shop.example.de</h1>" ]

  printf '<h1>@NAME@</h1>\n' >"$WELCOME_OVERRIDE"
  apply_welcome ow10042 shop.example.de
  grep -q 'Hier entsteht eine neue Webseite' "${WELCOME_DIR}/ow10042.html"
}

@test "the site templates show the placeholder only for the start page" {
  local file
  local file="site-backend.conf"
  grep -q 'index index.php index.html /.owpanel-welcome.html;' "${ROOT}/server/templates/nginx/${file}"
  grep -q 'alias /var/lib/owpanel/welcome/@USER@.html;' "${ROOT}/server/templates/nginx/${file}"
  grep -qF 'if ($request_uri !~ "^/(\?|$)")' "${ROOT}/server/templates/nginx/${file}"
}

@test "site-create accepts --existing" {
  sed "s#/opt/owpanel/lib/#${ROOT}/server/lib/#" "${ROOT}/server/bin/site-create" >"${BATS_TEST_TMPDIR}/site-create"
  run bash "${BATS_TEST_TMPDIR}/site-create" --domain example.de --type php --user ow38217 --existing --bogus
  [ "${lines[-1]}" = '{"ok":false,"script":"site-create","error":"invalid_arguments"}' ]
  run bash "${BATS_TEST_TMPDIR}/site-create" --domain example.de --type php --user ow38217 --existing
  [[ "${lines[-1]}" != *'"error":"invalid_arguments"'* ]]
}

@test "the command php of the cron jobs points to the version of the website (E-172)" {
  site_create_setup
  PHP_CLI_DIR="${BATS_TEST_TMPDIR}/php"
  install() {
    local -a args=()
    while [[ $# -gt 0 ]]; do
      case "$1" in -o | -g | -m) shift 2 ;; *) args+=("$1"); shift ;; esac
    done
    command install "${args[@]}"
  }
  apply_cli ow10042 8.5
  [ "$(readlink "${PHP_CLI_DIR}/ow10042/php")" = "/usr/bin/php8.5" ]
  apply_cli ow10042 8.6
  [ "$(readlink "${PHP_CLI_DIR}/ow10042/php")" = "/usr/bin/php8.6" ]
}

@test "only offered PHP versions are allowed for websites (E-172)" {
  owp_php_allowed 8.5
  owp_php_allowed 8.6
  ! owp_php_allowed 8.4
  ! owp_php_allowed 8.60
  ! owp_php_allowed ""
}

# PHP settings of a website (E-173).
settings_setup() {
  site_create_setup
  PHP_CLI_DIR="${BATS_TEST_TMPDIR}/php"
  declare -gA SETTINGS=()
  declare -gA SETTING_DEFAULT=([memory]=256 [upload]=64 [time]=60 [errors]=off [processes]=10)
  declare -gA SETTING_MIN=([memory]=32 [upload]=1 [time]=10 [processes]=1)
  declare -gA SETTING_MAX=([memory]=4096 [upload]=4096 [time]=3600 [processes]=100)
  mkdir -p "${PHP_CLI_DIR}/ow10042"
  install() {
    local -a args=()
    while [[ $# -gt 0 ]]; do
      case "$1" in -o | -g | -m) shift 2 ;; *) args+=("$1"); shift ;; esac
    done
    command install "${args[@]}"
  }
}

@test "PHP settings are checked against their bounds" {
  settings_setup
  valid_setting memory 512
  valid_setting errors on
  ! valid_setting memory 16
  ! valid_setting memory 08
  ! valid_setting time 99999
  ! valid_setting errors yes
  ! valid_setting processes ""
}

@test "a website keeps its PHP settings without new values" {
  settings_setup
  read_settings ow10042
  [ "${SETTINGS[memory]}" = 256 ] && [ "${SETTINGS[errors]}" = off ]
  SETTINGS[memory]=512
  SETTINGS[time]=120
  write_settings ow10042
  printf 'upload=99999\nevil=1\n' >>"${PHP_CLI_DIR}/ow10042/settings"
  read_settings ow10042
  [ "${SETTINGS[memory]}" = 512 ]
  [ "${SETTINGS[time]}" = 120 ]
  # A value out of bounds in the file falls back to the default.
  [ "${SETTINGS[upload]}" = 64 ]
  [ -z "${SETTINGS[evil]+set}" ]
}

@test "the pool and nginx carry the PHP settings" {
  settings_setup
  read_settings ow10042
  SETTINGS[memory]=512
  SETTINGS[upload]=128
  SETTINGS[time]=300
  SETTINGS[errors]=on
  SETTINGS[processes]=4
  owp_apply_config() { cp "$1" "${BATS_TEST_TMPDIR}/pool.conf"; }
  HTTPS=0
  write_pool ow10042 /var/www/ow10042 8.5
  local pool="${BATS_TEST_TMPDIR}/pool.conf"
  grep -qx 'pm.max_children = 4' "$pool"
  grep -qx 'php_admin_value\[memory_limit\] = 512M' "$pool"
  grep -qx 'php_admin_value\[upload_max_filesize\] = 128M' "$pool"
  grep -qx 'php_admin_value\[post_max_size\] = 136M' "$pool"
  grep -qx 'php_admin_value\[max_execution_time\] = 300' "$pool"
  grep -qx 'request_terminate_timeout = 330s' "$pool"
  grep -qx 'php_flag\[display_errors\] = on' "$pool"

  owp_site_backend "${ROOT}/server/templates/nginx" "${BATS_TEST_TMPDIR}/site.conf" example.de ow10042 /var/www/ow10042 136 330 127.0.0.1 127.0.0.1
  grep -q 'client_max_body_size 136m;' "${BATS_TEST_TMPDIR}/site.conf"
  grep -q 'fastcgi_read_timeout 330s;' "${BATS_TEST_TMPDIR}/site.conf"
  # The gateway carries the same limits, else it would refuse or cut off first (E-173).
  owp_site_gateway "${ROOT}/server/templates/nginx" "${BATS_TEST_TMPDIR}/gateway.conf" https example.de ow10042 0 0 136 330 01abc
  grep -q 'client_max_body_size 136m;' "${BATS_TEST_TMPDIR}/gateway.conf"
  grep -q 'proxy_read_timeout 330s;' "${BATS_TEST_TMPDIR}/gateway.conf"
}

@test "session cookies are secure only while visitors use HTTPS (E-176)" {
  settings_setup
  read_settings ow10042
  owp_apply_config() { cp "$1" "${BATS_TEST_TMPDIR}/pool.conf"; }
  local pool="${BATS_TEST_TMPDIR}/pool.conf"
  HTTPS=0
  write_pool ow10042 /var/www/ow10042 8.5
  grep -qx 'php_flag\[session.cookie_secure\] = off' "$pool"
  grep -qx 'php_flag\[session.cookie_httponly\] = on' "$pool"
  grep -qx 'php_flag\[session.use_strict_mode\] = on' "$pool"
  grep -qx 'php_value\[session.cookie_samesite\] = Lax' "$pool"
  HTTPS=1
  write_pool ow10042 /var/www/ow10042 8.5
  grep -qx 'php_flag\[session.cookie_secure\] = on' "$pool"
}

@test "static files get browser caching after the block for hidden files (E-176)" {
  local file conf
  for file in site-backend.conf; do
    conf="${ROOT}/server/templates/nginx/${file}"
    grep -q 'expires 7d;' "$conf"
    grep -q 'expires 30d;' "$conf"
    # nginx uses the first matching regex location, so hidden files must come first.
    [ "$(grep -n 'location ~ /\\.(?!well-known/)' "$conf" | cut -d: -f1)" -lt "$(grep -n 'location ~\* ' "$conf" | head -1 | cut -d: -f1)" ]
    [ "$(grep -n 'location ~ \\.php\$' "$conf" | cut -d: -f1)" -lt "$(grep -n 'location ~\* ' "$conf" | head -1 | cut -d: -f1)" ]
  done
}

@test "nginx compresses text and the provisioning installs it (E-176)" {
  local conf="${ROOT}/server/templates/nginx/owpanel-compression.conf"
  grep -qx 'gzip on;' "$conf"
  grep -qx 'gzip_vary on;' "$conf"
  grep -q 'text/css' "$conf"
  grep -q 'owpanel-compression' "${ROOT}/server/provision/modules/nginx.sh"
}

@test "site-create refuses PHP settings out of bounds" {
  sed "s#/opt/owpanel/lib/#${ROOT}/server/lib/#" "${ROOT}/server/bin/site-create" >"${BATS_TEST_TMPDIR}/site-create"
  run bash "${BATS_TEST_TMPDIR}/site-create" --domain example.de --type php --user ow38217 --php-memory 99999
  [ "${lines[-1]}" = '{"ok":false,"script":"site-create","error":"invalid_php_setting"}' ]
  run bash "${BATS_TEST_TMPDIR}/site-create" --domain example.de --type php --user ow38217 --php-errors maybe
  [ "${lines[-1]}" = '{"ok":false,"script":"site-create","error":"invalid_php_setting"}' ]
  run bash "${BATS_TEST_TMPDIR}/site-create" --domain example.de --type php --user ow38217 --php-memory 512 --php-processes 4
  [[ "${lines[-1]}" != *'"error":"invalid'* ]]
}

@test "refusals of hidden files go to the shared log for fail2ban (E-174)" {
  local file
  for file in nginx/gateway-site-http.conf nginx/gateway-site-https.conf panel/nginx-https.conf; do
    awk '/location ~ \/\\.\(\?!well-known\/\) \{/,/\}/' "${ROOT}/server/templates/${file}" >"${BATS_TEST_TMPDIR}/deny"
    grep -q 'error_log /var/log/nginx/owpanel-hidden.log;' "${BATS_TEST_TMPDIR}/deny"
    grep -q 'deny all;' "${BATS_TEST_TMPDIR}/deny"
  done
}

@test "the fail2ban filter finds scanners for hidden files and nothing else (E-174)" {
  command -v fail2ban-regex >/dev/null || skip "fail2ban-regex is missing"
  cat >"${BATS_TEST_TMPDIR}/hidden.log" <<'LOG'
2026/11/23 04:36:18 [error] 13410#13410: *1802 access forbidden by rule, client: 3.252.247.209, server: owpanel.org, request: "GET /newsletter/.env HTTP/1.1", host: "owpanel.org"
2026/11/23 04:36:19 [error] 13410#13410: *1803 access forbidden by rule, client: 2001:db8::5, server: owpanel.org, request: "GET /.git/config HTTP/1.1", host: "owpanel.org"
2026/11/23 04:36:19 [error] 13410#13410: *1804 open() "/var/www/x" failed (2: No such file or directory), client: 198.51.100.7, server: owpanel.org
LOG
  run fail2ban-regex "${BATS_TEST_TMPDIR}/hidden.log" "${ROOT}/server/templates/fail2ban/owpanel-hidden.conf"
  [ "$status" -eq 0 ]
  [[ "$output" == *"Lines: 3 lines, 0 ignored, 2 matched, 1 missed"* ]]
}
