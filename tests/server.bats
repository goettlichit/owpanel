#!/usr/bin/env bats
# Runs without root and changes nothing outside a temporary directory.

ROOT="${BATS_TEST_DIRNAME}/.."

setup() {
  # shellcheck source=server/lib/common.sh
  source "${ROOT}/server/lib/common.sh"
  VALID_KEY="ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIJ8Vbq0wWm5s3cZc1lqkXn2yK7tGfTQd0hH4pR6uE1xA"
}

write_key() {
  printf '%b' "$1" >"${BATS_TEST_TMPDIR}/key.pub"
}

@test "accepts an Ed25519 key and drops its comment" {
  write_key "${VALID_KEY} panel@host\n"
  owp_read_public_key "${BATS_TEST_TMPDIR}/key.pub"
  [ "$OWP_PUBLIC_KEY" = "$VALID_KEY" ]
}

@test "rejects manipulated public keys" {
  local bad
  for bad in \
    "" \
    "command=\"/bin/sh\" ${VALID_KEY}\n" \
    "ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAAAgQC\n" \
    "${VALID_KEY}\n${VALID_KEY}\n" \
    "${VALID_KEY} a;touch /tmp/x\n" \
    "${VALID_KEY}\$(id)\n" \
    "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5short\n"; do
    write_key "$bad"
    run owp_read_public_key "${BATS_TEST_TMPDIR}/key.pub"
    [ "$status" -eq 1 ]
    [[ "${lines[-1]}" == *'"error":"invalid_public_key"'* ]]
  done
}

@test "rejects a symlink as key file" {
  write_key "${VALID_KEY}\n"
  ln -s "${BATS_TEST_TMPDIR}/key.pub" "${BATS_TEST_TMPDIR}/link.pub"
  run owp_read_public_key "${BATS_TEST_TMPDIR}/link.pub"
  [ "$status" -eq 1 ]
}

@test "setup-agent reports the error code as last output line" {
  write_key "not a key\n"
  run "${ROOT}/server/provision/setup-agent" --public-key-file "${BATS_TEST_TMPDIR}/key.pub"
  [ "$status" -eq 1 ]
  [ "${lines[-1]}" = '{"ok":false,"script":"setup-agent","error":"invalid_public_key"}' ]
}

@test "setup-agent rejects unknown and incomplete parameters" {
  local args
  for args in "--bogus" "--public-key-file" "positional"; do
    # shellcheck disable=SC2086  # each entry is a parameter list
    run "${ROOT}/server/provision/setup-agent" $args
    [ "$status" -eq 1 ]
    [[ "${lines[-1]}" == *'"error":"invalid_arguments"'* ]]
  done
}

@test "accepts Debian 13 only" {
  printf 'ID=debian\nVERSION_ID="13"\n' >"${BATS_TEST_TMPDIR}/os"
  run owp_check_os "${BATS_TEST_TMPDIR}/os"
  [ "$status" -eq 0 ]
  for content in 'ID=debian\nVERSION_ID="12"\n' 'ID=ubuntu\nVERSION_ID="13"\n' ''; do
    printf '%b' "$content" >"${BATS_TEST_TMPDIR}/os"
    run owp_check_os "${BATS_TEST_TMPDIR}/os"
    [ "$status" -eq 1 ]
  done
}

@test "does not execute os-release content" {
  printf 'ID=debian\nVERSION_ID="13"\ntouch %s/pwned\n' "$BATS_TEST_TMPDIR" >"${BATS_TEST_TMPDIR}/os"
  run owp_check_os "${BATS_TEST_TMPDIR}/os"
  [ ! -e "${BATS_TEST_TMPDIR}/pwned" ]
}

@test "parses SSH ports from sshd -T output" {
  run owp_parse_ssh_ports <<<$'port 2222\nport 22\nlistenaddress 0.0.0.0:22\nlistenaddress [::]:2200\npermitrootlogin no'
  [ "$output" = $'22\n2200\n2222' ]
}

@test "ignores invalid SSH ports" {
  run owp_parse_ssh_ports <<<$'port 0\nport 70000\nport 22;id\nport abc'
  [ -z "$output" ]
}

# Tests for owp_apply_config use a stub, so no service is touched.
apply_setup() {
  SRC="${BATS_TEST_TMPDIR}/new.conf"
  DEST="${BATS_TEST_TMPDIR}/live.conf"
  CALLS="${BATS_TEST_TMPDIR}/calls"
  printf 'new\n' >"$SRC"
  printf 'old\n' >"$DEST"
  install() { command cp "$7" "$8"; command chmod "$6" "$8"; }
  owp_service_action() { printf '%s\n' "$2" >>"$CALLS"; return "${SERVICE_RC:-0}"; }
  SERVICE_RC=0
}

@test "applies a valid config" {
  apply_setup
  owp_apply_config "$SRC" "$DEST" 0644 demo reload -- true
  [ "$(cat "$DEST")" = "new" ]
  [ "$(cat "$CALLS")" = "reload" ]
}

@test "restores the previous config when validation fails" {
  apply_setup
  run owp_apply_config "$SRC" "$DEST" 0644 demo reload -- false
  [ "$status" -eq 1 ]
  [ "$(cat "$DEST")" = "old" ]
  [ "$(cat "$CALLS")" = "reload" ]
}

@test "restores the previous config when the service fails" {
  apply_setup
  SERVICE_RC=1
  run owp_apply_config "$SRC" "$DEST" 0644 demo reload -- true
  [ "$status" -eq 1 ]
  [ "$(cat "$DEST")" = "old" ]
  [ "$(wc -l <"$CALLS")" -eq 2 ]
}

@test "removes a new config that fails validation" {
  apply_setup
  rm "$DEST"
  run owp_apply_config "$SRC" "$DEST" 0644 demo reload -- false
  [ "$status" -eq 1 ]
  [ ! -e "$DEST" ]
}

@test "a second run changes nothing" {
  apply_setup
  cp "$SRC" "$DEST"
  owp_apply_config "$SRC" "$DEST" 0644 demo reload -- false
  [ ! -e "$CALLS" ]
}

@test "sudo rules allow only exact script paths" {
  local file="${ROOT}/server/templates/sudoers/owpanel-agent"
  run grep -Ev '^(#|$|Defaults:owpanel-agent env_reset$|owpanel-agent ALL=\(root\) NOPASSWD: OWPANEL_SCRIPTS$|Cmnd_Alias OWPANEL_SCRIPTS = /opt/owpanel/bin/[a-z0-9-]+, \\$| +/opt/owpanel/bin/[a-z0-9-]+(, \\)?$)' "$file"
  [ "$status" -eq 1 ]
  ! grep -Eq '\*|SETENV|ALL *$|/(ba|da|z)?sh\b|/(apt|apt-get|dpkg|vi|vim|nano|env|su|sudo)\b' <(grep -v '^#' "$file")
}

@test "sudo rules pass visudo" {
  command -v visudo || skip "visudo not available"
  visudo -cf "${ROOT}/server/templates/sudoers/owpanel-agent"
}

@test "scripts follow the shell rules" {
  local script
  for script in "${ROOT}/server/provision/setup-agent" "${ROOT}/server/bin/"*; do
    [ "$(sed -n 2,15p "$script" | grep -c '^set -euo pipefail$')" -eq 1 ]
  done
  ! grep -rn --include='*' -E '(^|[^_[:alnum:]])eval ' "${ROOT}/server"
}

@test "removes a config and keeps it when validation fails" {
  apply_setup
  run owp_remove_config "$DEST" demo reload -- false
  [ "$status" -eq 1 ]
  [ "$(cat "$DEST")" = "old" ]
  owp_remove_config "$DEST" demo reload -- true
  [ ! -e "$DEST" ]
  run owp_remove_config "$DEST" demo reload -- false
  [ "$status" -eq 0 ]
  [[ "$output" == "already absent: "* ]]
}

@test "only the repositories module stops on a failing apt update" {
  # Everything else must get past a broken source, because the repositories
  # module is what repairs it.
  run grep -rnE '^[[:space:]]*owp_apt_update[[:space:]]*$' "${ROOT}/server"
  [ "$status" -eq 1 ] || { echo "$output"; return 1; }
  run grep -rn 'owp_apt_update ||' "${ROOT}/server"
  [[ "$output" == *"repository_update_failed"* ]]
  [ "$(grep -c 'owp_apt_update ||' <<<"$output")" -eq 2 ]
  [ "$(grep 'owp_apt_update ||' <<<"$output" | grep -vc 'repositories.sh\|common.sh')" -eq 0 ]
}

@test "installed packages need neither apt update nor apt-get install" {
  dpkg-query() { printf 'install ok installed'; }
  apt-get() { echo "apt-get $*" >>"${BATS_TEST_TMPDIR}/calls"; }
  run owp_apt_install curl gnupg
  [ "$status" -eq 0 ]
  [ "$output" = "installed: curl gnupg" ]
  [ ! -e "${BATS_TEST_TMPDIR}/calls" ]
}

@test "a missing package triggers one apt update per run" {
  dpkg-query() { printf 'unknown ok not-installed'; }
  apt-get() { echo "$*" >>"${BATS_TEST_TMPDIR}/calls"; }
  systemctl() { return 3; }
  pgrep() { return 1; }
  owp_apt_install foo
  owp_apt_install bar
  [ "$(grep -c ' update$' "${BATS_TEST_TMPDIR}/calls")" -eq 1 ]
  [ "$(grep -c ' install ' "${BATS_TEST_TMPDIR}/calls")" -eq 2 ]
}

@test "the always running unattended-upgrade-shutdown does not block package installs" {
  dpkg-query() { printf 'unknown ok not-installed'; }
  apt-get() { :; }
  systemctl() { return 3; }
  sleep() { echo "slept" >>"${BATS_TEST_TMPDIR}/slept"; }
  # Only the short process name matches, like unattended-upgrade-shutdown.
  pgrep() { [[ "$*" == "-x unattended-upgr" ]]; }
  run owp_apt_install nginx
  [ "$status" -eq 0 ]
  [ ! -e "${BATS_TEST_TMPDIR}/slept" ]
}

@test "package installs wait for the automatic updates and try again" {
  dpkg-query() { printf 'unknown ok not-installed'; }
  systemctl() { return 3; }
  sleep() { :; }
  pkill() { echo "pkill $*" >>"${BATS_TEST_TMPDIR}/calls"; }
  dpkg() { echo "dpkg $*" >>"${BATS_TEST_TMPDIR}/calls"; }
  # unattended-upgrades runs for the first checks, then apt-get fails once.
  pgrep() {
    local n
    n=$(($(cat "${BATS_TEST_TMPDIR}/checks" 2>/dev/null || echo 0) + 1))
    echo "$n" >"${BATS_TEST_TMPDIR}/checks"
    [[ "$n" -le 3 && "$*" == *unattended-upgr* ]]
  }
  apt-get() {
    echo "$*" >>"${BATS_TEST_TMPDIR}/calls"
    [[ "$*" != *" install "* ]] || [[ -e "${BATS_TEST_TMPDIR}/failed" ]] || { touch "${BATS_TEST_TMPDIR}/failed"; return 100; }
  }
  run owp_apt_install nginx
  [ "$status" -eq 0 ]
  [[ "$output" == *"pausing the automatic updates"* ]]
  [[ "$output" == *"trying again (1/3)"* ]]
  grep -q '^pkill -TERM -f /usr/bin/unattended-upgrade' "${BATS_TEST_TMPDIR}/calls"
  grep -q '^dpkg --configure -a' "${BATS_TEST_TMPDIR}/calls"
  [ "$(grep -c ' install ' "${BATS_TEST_TMPDIR}/calls")" -eq 2 ]
}

@test "the run that paused the automatic updates starts them again, its child scripts do not" {
  systemctl() { echo "systemctl $*" >>"${BATS_TEST_TMPDIR}/calls"; return 3; }
  pgrep() { return 1; }
  export -f systemctl pgrep
  # A child script of a paused run leaves the timers alone.
  OWP_UPDATES_PAUSED=1 bash -c "source ${ROOT}/server/lib/common.sh; owp_apt_pause_updates; owp_apt_resume_updates"
  [ ! -e "${BATS_TEST_TMPDIR}/calls" ]

  owp_apt_pause_updates
  [ "$OWP_UPDATES_PAUSED" = 1 ]
  grep -q 'systemctl stop apt-daily.timer apt-daily-upgrade.timer' "${BATS_TEST_TMPDIR}/calls"
  OWP_KEEP_UPDATES_PAUSED=1 owp_apt_resume_updates
  ! grep -q 'systemctl start' "${BATS_TEST_TMPDIR}/calls"
  OWP_APT_PAUSED=1
  owp_apt_resume_updates
  grep -q 'systemctl start apt-daily.timer apt-daily-upgrade.timer' "${BATS_TEST_TMPDIR}/calls"
}

@test "accepts real time zones only" {
  [ -f /usr/share/zoneinfo/Europe/Berlin ] || skip "no zoneinfo on this system"
  local zone
  for zone in Europe/Berlin UTC America/New_York America/Argentina/Buenos_Aires; do
    owp_valid_timezone "$zone" || { echo "rejected: $zone"; return 1; }
  done
  for zone in "" europe/berlin Europe/Nowhere "Europe/Berlin;id" '../../etc/passwd' \
    "Europe/../../etc/passwd" "/etc/passwd" "Europe/Berlin " 'Europe/$(id)' $'Europe/Berlin\nUTC'; do
    if owp_valid_timezone "$zone"; then echo "accepted: $zone"; return 1; fi
  done
}

@test "automatic updates cover the OWPanel sources and never reboot" {
  local file="${ROOT}/server/templates/apt/52owpanel-unattended-upgrades"
  grep -q '"site=nginx.org";' "$file"
  grep -q '"site=packages.sury.org";' "$file"
  grep -q 'Automatic-Reboot "false";' "$file"
  grep -q 'APT::Periodic::Unattended-Upgrade "1";' "${ROOT}/server/templates/apt/21owpanel-auto-upgrades"
  if command -v apt-config >/dev/null; then
    apt-config -c "$file" dump >/dev/null
    apt-config -c "${ROOT}/server/templates/apt/21owpanel-auto-upgrades" dump >/dev/null
  fi
}

@test "PHP versions are installed only as final releases (E-172)" {
  run bash -c '
    source "'"${ROOT}"'/server/lib/common.sh"
    source <(grep -v "^readonly OWP_PHP_EXTENSIONS" "'"${ROOT}"'/server/provision/modules/php.sh")
    owp_warn() { printf "warn: %s\n" "$*"; }
    apt-cache() { printf "php%s-fpm:\n  Installed: (none)\n  Candidate: %s\n" 8.6 "$CANDIDATE"; }
    for CANDIDATE in "8.6.0-1+0~20261119.1+debian13~1.gbp1" "8.6.0~rc5-1+0~20261105.1" "(none)"; do
      if php_release_available 8.6; then echo "yes ${CANDIDATE}"; else echo "no ${CANDIDATE}"; fi
    done
  '
  [ "$status" -eq 0 ]
  [[ "$output" == *"yes 8.6.0-1+0~20261119.1+debian13~1.gbp1"* ]]
  [[ "$output" == *"no 8.6.0~rc5-1+0~20261105.1"* ]]
  [[ "$output" == *"no (none)"* ]]
  [[ "$output" == *"only available as pre-release"* ]]
}

@test "every PHP version gets its own socket for the idle www pool" {
  # shellcheck source=server/lib/site.sh
  source "${ROOT}/server/lib/site.sh"
  owp_render "${ROOT}/server/templates/php/www-pool.conf" "${BATS_TEST_TMPDIR}/a" VERSION=8.5
  owp_render "${ROOT}/server/templates/php/www-pool.conf" "${BATS_TEST_TMPDIR}/b" VERSION=8.6
  grep -qx 'listen = /run/php/owpanel-idle-8.5.sock' "${BATS_TEST_TMPDIR}/a"
  grep -qx 'listen = /run/php/owpanel-idle-8.6.sock' "${BATS_TEST_TMPDIR}/b"
}
