#!/usr/bin/env bats
# Tests for site-suspend and site-unsuspend (E-127). Argument checks and the
# rewriting of the nginx file; suspending a real website needs root, nginx
# and PHP-FPM and is tested on a VM.

ROOT="${BATS_TEST_DIRNAME}/.."

setup() {
  for script in site-suspend site-unsuspend; do
    sed "s#/opt/owpanel/lib/#${ROOT}/server/lib/#" "${ROOT}/server/bin/${script}" >"${BATS_TEST_TMPDIR}/${script}"
  done
}

@test "both scripts check the user before anything else" {
  local script
  for script in site-suspend site-unsuspend; do
    run bash "${BATS_TEST_TMPDIR}/${script}"
    [[ "${lines[-1]}" == *'"error":"invalid_arguments"'* ]]
    run bash "${BATS_TEST_TMPDIR}/${script}" --user root
    [[ "${lines[-1]}" == *'"error":"invalid_user"'* ]]
    run bash "${BATS_TEST_TMPDIR}/${script}" --user ow10000
    [[ "${lines[-1]}" == *'"error":"reserved_user"'* ]]
    run bash "${BATS_TEST_TMPDIR}/${script}" --user ow38217 --force
    [[ "${lines[-1]}" == *'"error":"invalid_arguments"'* ]]
  done
}

@test "the include follows every server_name and goes away without a trace" {
  source "${ROOT}/server/lib/common.sh"
  source "${ROOT}/server/lib/site.sh"
  source "${ROOT}/server/lib/suspend.sh"
  local site="${BATS_TEST_TMPDIR}/site.conf"
  owp_site_gateway "${ROOT}/server/templates/nginx" "$site" https shop.example ow38217 1 1 72 90 01abc

  owp_suspend_nginx_with "$site" >"${BATS_TEST_TMPDIR}/suspended.conf"
  [ "$(grep -c 'server_name' "$site")" -eq "$(grep -c '# owpanel-suspended' "${BATS_TEST_TMPDIR}/suspended.conf")" ]
  [ "$(grep -c 'server_name' "$site")" -ge 4 ]
  # Every include sits right after a server_name line.
  run awk 'index($0, "# owpanel-suspended") && prev !~ /server_name/ { bad = 1 } { prev = $0 } END { exit bad }' "${BATS_TEST_TMPDIR}/suspended.conf"
  [ "$status" -eq 0 ]

  # A second run adds nothing.
  owp_suspend_nginx_with "${BATS_TEST_TMPDIR}/suspended.conf" >"${BATS_TEST_TMPDIR}/twice.conf"
  cmp "${BATS_TEST_TMPDIR}/suspended.conf" "${BATS_TEST_TMPDIR}/twice.conf"

  owp_suspend_nginx_without "${BATS_TEST_TMPDIR}/suspended.conf" >"${BATS_TEST_TMPDIR}/back.conf"
  cmp "$site" "${BATS_TEST_TMPDIR}/back.conf"
}

@test "the snippet keeps the ACME path open and the page internal" {
  local snippet="${ROOT}/server/templates/nginx/site-suspended.conf"
  grep -q 'return 503;' "$snippet"
  grep -qF 'acme-challenge' "$snippet"
  grep -q 'internal;' "$snippet"
  grep -q 'root /var/lib/owpanel/suspended-page;' "$snippet"
  grep -q 'Diese Webseite ist vorübergehend nicht erreichbar.' "${ROOT}/server/templates/nginx/site-suspended.html"
}

@test "kept pool files name their PHP version" {
  source "${ROOT}/server/lib/common.sh"
  source "${ROOT}/server/lib/suspend.sh"
  [ "$(owp_suspend_php_version /etc/php/8.4/fpm/pool.d/owpanel-ow38217.conf)" = "8.4" ]
  [ "$(owp_suspend_php_version /var/lib/owpanel/suspended/ow38217/pool-8.5.conf)" = "8.5" ]
  run owp_suspend_php_version /var/lib/owpanel/suspended/ow38217/pool-x.conf
  [ "$status" -ne 0 ]
}
