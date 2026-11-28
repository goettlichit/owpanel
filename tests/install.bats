#!/usr/bin/env bats
# Tests for the installer helpers in server/lib/install.sh. Run without root
# and change nothing on the system.

ROOT="${BATS_TEST_DIRNAME}/.."

setup() {
  # The installer runs with this locale (owp_init); names may contain umlauts.
  export LC_ALL=C.UTF-8
  # shellcheck source=server/lib/common.sh
  source "${ROOT}/server/lib/common.sh"
  # shellcheck source=server/lib/site.sh
  source "${ROOT}/server/lib/site.sh"
  # shellcheck source=server/lib/install.sh
  source "${ROOT}/server/lib/install.sh"
}

@test "versions of tagged and branch builds are accepted" {
  local version
  for version in 1.0.0 0.3.12 1.2.3-rc.1 dev-226664567fa4 dev-abcdef0; do
    owp_valid_version "$version" || { echo "rejected: $version"; return 1; }
  done
}

@test "other versions are rejected" {
  local version
  for version in "" v1.0.0 1.0 1.0.0-rc/1 "1.0.0 " dev- dev-XYZ1234 dev-abc '1.0.0;id' $'1.0.0\n2'; do
    ! owp_valid_version "$version" || { echo "accepted: $version"; return 1; }
  done
}

@test "plain e-mail addresses are accepted, anything odd is not" {
  owp_valid_email admin@example.de
  owp_valid_email first.last+panel@mail.example.co.uk
  local email
  for email in "" admin example.de "a b@example.de" 'a"b@example.de' "admin@example" \
    "admin@Example.de" "admin@example..de" "a..b@example.de" $'admin@example.de\n' "admin@-example.de"; do
    ! owp_valid_email "$email" || { echo "accepted: $email"; return 1; }
  done
}

@test "names allow letters of any language, hyphens and apostrophes" {
  owp_valid_person_name "Jörg"
  owp_valid_person_name "Anna-Lena"
  owp_valid_person_name "O'Brien"
  owp_valid_person_name "de la Cruz"
  local name
  for name in "" " Anna" "Anna " '$(id)' "Anna;id" $'Anna\nB' "-Anna" "$(printf 'a%.0s' {1..101})"; do
    ! owp_valid_person_name "$name" || { echo "accepted: $name"; return 1; }
  done
}

@test "passwords need 12 characters and no control characters" {
  owp_valid_password "correct horse battery"
  ! owp_valid_password "short"
  ! owp_valid_password $'correct horse\nbattery'
  ! owp_valid_password "$(printf 'a%.0s' {1..256})"
}

@test "the domain check compares DNS with the own addresses" {
  owp_local_addresses() { printf '203.0.113.10\n2001:db8::10\n'; }

  owp_resolve_domain() { printf '203.0.113.10\n'; }
  owp_domain_points_here panel.example.de

  owp_resolve_domain() { printf '198.51.100.7\n2001:db8::10\n'; }
  owp_domain_points_here panel.example.de

  owp_resolve_domain() { printf '198.51.100.7\n'; }
  run owp_domain_points_here panel.example.de
  [ "$status" -eq 1 ]

  owp_resolve_domain() { :; }
  run owp_domain_points_here panel.example.de
  [ "$status" -eq 2 ]
}

@test "the domain check ignores /etc/hosts" {
  local name="owp-hosts-test-$$.invalid"
  [[ "$(id -u)" -eq 0 ]] || skip "needs root to change /etc/hosts"
  printf '127.0.1.1 %s\n' "$name" >>/etc/hosts
  run owp_resolve_domain "$name"
  sed -i "/ ${name}\$/d" /etc/hosts
  [ "$status" -eq 0 ]
  [ -z "$output" ]
}

@test "random secrets have the expected form" {
  [[ "$(owp_random_hex)" =~ ^[0-9a-f]{64}$ ]]
  [[ "$(owp_random_app_key)" =~ ^base64:[A-Za-z0-9+/]{43}=$ ]]
  [ "$(owp_random_hex)" != "$(owp_random_hex)" ]
}

@test "the .env holds the given values and production settings" {
  local env="${BATS_TEST_TMPDIR}/.env"
  owp_render_env "$env" panel.example.de 1.2.0 Europe/Berlin base64:KEY abc123 /var/lib/owpanel-worker/ssh/id_ed25519
  grep -qx 'APP_URL=https://panel.example.de' "$env"
  grep -qx 'APP_ENV=production' "$env"
  grep -qx 'APP_DEBUG=false' "$env"
  grep -qx 'APP_KEY=base64:KEY' "$env"
  grep -qx 'DB_PASSWORD=abc123' "$env"
  grep -qx 'OWPANEL_VERSION=1.2.0' "$env"
  grep -qx 'OWPANEL_TIMEZONE=Europe/Berlin' "$env"
  grep -qx 'OWPANEL_SSH_PRIVATE_KEY=/var/lib/owpanel-worker/ssh/id_ed25519' "$env"
  grep -qx 'SESSION_SECURE_COOKIE=true' "$env"
  [ "$(owp_read_setting "$env" DB_PASSWORD)" = "abc123" ]
}

@test "settings are replaced in place and added when missing" {
  local env="${BATS_TEST_TMPDIR}/.env"
  printf 'A=1\nOWPANEL_VERSION=1.0.0\nB=2\n' >"$env"
  chmod 0640 "$env"
  owp_write_setting "$env" OWPANEL_VERSION 1.1.0
  owp_write_setting "$env" NEW 3
  [ "$(cat "$env")" = $'A=1\nOWPANEL_VERSION=1.1.0\nB=2\nNEW=3' ]
  [ "$(stat -c %a "$env")" = "640" ]
}

@test "the panel's nginx configuration renders without leftovers" {
  local out="${BATS_TEST_TMPDIR}/panel.conf"
  owp_render "${ROOT}/server/templates/panel/nginx-https.conf" "$out" DOMAIN=panel.example.de
  grep -q 'server_name panel.example.de;' "$out"
  grep -q 'return 301 https://panel.example.de$request_uri;' "$out"
  grep -q 'listen 443 quic;' "$out"
  grep -q 'http3 on;' "$out"
  ! grep -q '@' "$out"
}

@test "repository_state works on a fresh server without OWPanel's files" {
  set -o pipefail
  # shellcheck source=server/provision/modules/repositories.sh
  source "${ROOT}/server/provision/modules/repositories.sh"
  run repository_state
  [ "$status" -eq 0 ]
  [[ "$output" =~ ^[0-9a-f]{64} ]]
}

@test "the public address helper prints one address or nothing" {
  set -o pipefail
  run owp_public_address 4
  [ "$status" -eq 0 ]
  [[ -z "$output" || "$output" =~ ^[0-9.]+$ ]]
  run owp_public_address 6
  [ "$status" -eq 0 ]
  [[ -z "$output" || "$output" =~ ^[0-9a-f:]+$ ]]
}

# Loads panel_query and wait_for_rebuild from the installer, with a fake
# mariadb that answers from files: first the rebuild is still open, then it
# has ended (E-106).
load_wait_for_rebuild() {
  local bin="${BATS_TEST_TMPDIR}/bin"
  mkdir -p "$bin"
  cat >"${bin}/mariadb" <<'STUB'
#!/usr/bin/env bash
query="${*: -1}"
round="$(cat "${BATS_TEST_TMPDIR}/round" 2>/dev/null || echo 0)"
case "$query" in
  *"FROM jobs"*) [[ "$round" -eq 0 ]] && echo 1 || echo 0 ;;
  *"SELECT COALESCE(SUM"*)
    printf '%s\n' "$query" >>"${BATS_TEST_TMPDIR}/queries"
    [[ "$round" -eq 0 ]] && echo "2 1 0 0" || cat "${BATS_TEST_TMPDIR}/final"
    echo $((round + 1)) >"${BATS_TEST_TMPDIR}/round" ;;
  *"t.status = 'running'"*) echo "site-restore shop.example.de" ;;
esac
STUB
  chmod +x "${bin}/mariadb"
  PATH="${bin}:${PATH}"
  sleep() { :; }
  ok() { echo "ok: $*"; }
  owp_warn() { echo "warn: $*"; }
  owp_log() { echo "log: $*"; }
  C_DIM="" C_RESET=""
  REBUILD_SINCE="2026-11-13 08:00:00"
  eval "$(sed -n -e '/^panel_query() {/,/^}/p' -e '/^wait_for_rebuild() {/,/^}/p' "${ROOT}/install")"
}

@test "the installer waits for the rebuild of the websites before the restart" {
  load_wait_for_rebuild
  echo "0 6 0 0" >"${BATS_TEST_TMPDIR}/final"
  run wait_for_rebuild
  [ "$status" -eq 0 ]
  [[ "$output" == *"log: rebuild: 1 done, 2 open, now site-restore shop.example.de"* ]]
  [[ "$output" == *"ok: websites rebuilt: 6 steps"* ]]
  grep -q "created_at >= '2026-11-13 08:00:00'" "${BATS_TEST_TMPDIR}/queries"
}

@test "a failed rebuild still ends the wait, with a warning" {
  load_wait_for_rebuild
  echo "0 4 1 2" >"${BATS_TEST_TMPDIR}/final"
  run wait_for_rebuild
  [ "$status" -eq 0 ]
  [[ "$output" == *"warn: rebuild ended: 4 steps done, 1 failed, 2 skipped; see Server -> Tasks"* ]]
}

@test "host names: short or full, lower case, never digits only first" {
  # shellcheck source=server/lib/hostname.sh
  source "${ROOT}/server/lib/hostname.sh"
  local name
  for name in srv03 srv03.goettlich.it a web-1.example.de 3web.example.de "$(printf 'a%.0s' {1..63})"; do
    owp_valid_hostname "$name" || { echo "rejected: $name"; return 1; }
  done
  for name in "" SRV03 srv_03 -srv srv- srv03. .srv03 srv..de 1234 1234.example.de "srv 03" 'srv;id' \
    $'srv\n03' "$(printf 'a%.0s' {1..64})" "$(printf 'abcdefghi.%.0s' {1..26})de"; do
    ! owp_valid_hostname "$name" || { echo "accepted: $name"; return 1; }
  done
}

@test "the hosts file gets the new name and keeps everything else" {
  source "${ROOT}/server/lib/hostname.sh"
  local hosts="${BATS_TEST_TMPDIR}/hosts"
  printf '%s\n' "# comment" "127.0.0.1 localhost" "127.0.1.1 debian-4gb-nbg1-1 debian-4gb-nbg1-1" \
    "::1 localhost ip6-localhost" "127.0.1.1 again" >"$hosts"
  run owp_hosts_with_name "$hosts" srv03.goettlich.it
  [ "${lines[0]}" = "# comment" ]
  [ "${lines[2]}" = $'127.0.1.1\tsrv03.goettlich.it srv03' ]
  [ "${lines[3]}" = "::1 localhost ip6-localhost" ]
  [ "${#lines[@]}" -eq 4 ]

  printf '%s\n' "127.0.0.1 localhost" "::1 localhost" >"$hosts"
  run owp_hosts_with_name "$hosts" srv03
  [ "${lines[1]}" = $'127.0.1.1\tsrv03' ]
  [ "${lines[2]}" = "::1 localhost" ]

  printf '%s\n' "::1 localhost" >"$hosts"
  run owp_hosts_with_name "$hosts" srv03
  [ "${lines[1]}" = $'127.0.1.1\tsrv03' ]
}

@test "setting the host name writes hosts and cloud-init and rolls back on failure" {
  source "${ROOT}/server/lib/hostname.sh"
  export OWP_HOSTS_FILE="${BATS_TEST_TMPDIR}/hosts" OWP_CLOUD_CFG_DIR="${BATS_TEST_TMPDIR}/cloud"
  mkdir -p "$OWP_CLOUD_CFG_DIR"
  printf '%s\n' "127.0.0.1 localhost" "127.0.1.1 old" >"$OWP_HOSTS_FILE"
  hostnamectl() { echo "$*" >"${BATS_TEST_TMPDIR}/called"; }
  run owp_set_hostname srv03.goettlich.it
  [ "$status" -eq 0 ]
  [ "$(cat "${BATS_TEST_TMPDIR}/called")" = "set-hostname srv03" ]
  grep -qx $'127.0.1.1\tsrv03.goettlich.it srv03' "$OWP_HOSTS_FILE"
  grep -qx "preserve_hostname: true" "${OWP_CLOUD_CFG_DIR}/99-owpanel-hostname.cfg"
  grep -qx "manage_etc_hosts: false" "${OWP_CLOUD_CFG_DIR}/99-owpanel-hostname.cfg"

  printf '%s\n' "127.0.0.1 localhost" "127.0.1.1 old" >"$OWP_HOSTS_FILE"
  hostnamectl() { return 1; }
  run owp_set_hostname srv04
  [ "$status" -ne 0 ]
  [[ "${lines[-1]}" == *'"error":"hostname_failed"'* ]]
  grep -qx "127.0.1.1 old" "$OWP_HOSTS_FILE"
  [ "$(find "$BATS_TEST_TMPDIR" -name 'hosts.owpanel*' | wc -l)" -eq 0 ]

  run owp_set_hostname "Bad_Name"
  [[ "${lines[-1]}" == *'"error":"invalid_hostname"'* ]]
}

@test "an update keeps the active and the previous release only" {
  local releases="${BATS_TEST_TMPDIR}/panel/releases" name
  # shellcheck source=/dev/null
  source <(sed -n '/^prune_releases() {$/,/^}$/p' "${ROOT}/install")
  ok() { :; }
  mkdir -p "$releases"
  for name in 0.2.2 0.3.0 dev-063b33a4432b 0.4.0 .new.Ab12Cd 0.5.0; do
    mkdir -p "${releases}/${name}"
    touch -d "@$((1700000000 + ${#name} + RANDOM % 5))" "${releases}/${name}"
  done
  touch -d @1700000100 "${releases}/0.4.0"
  touch -d @1700000200 "${releases}/0.5.0"
  mkdir -p "${BATS_TEST_TMPDIR}/elsewhere" && ln -s "${BATS_TEST_TMPDIR}/elsewhere" "${releases}/link"
  mkdir -p "${releases}/notes"

  PANEL_ROOT="${BATS_TEST_TMPDIR}/panel" VERSION="0.5.0" prune_releases

  [ -d "${releases}/0.5.0" ]
  [ -d "${releases}/0.4.0" ]
  [ ! -e "${releases}/0.3.0" ]
  [ ! -e "${releases}/0.2.2" ]
  [ ! -e "${releases}/dev-063b33a4432b" ]
  [ ! -e "${releases}/.new.Ab12Cd" ]
  # Anything that is no release stays.
  [ -L "${releases}/link" ]
  [ -d "${BATS_TEST_TMPDIR}/elsewhere" ]
  [ -d "${releases}/notes" ]
}
