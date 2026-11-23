#!/usr/bin/env bats
# Tests for server/bin/site-sftp. Changes the test user ow38217, so it only
# runs as root in a throwaway container or VM that has this user.

ROOT="${BATS_TEST_DIRNAME}/.."

setup() {
  [[ "$(id -u)" -eq 0 ]] || skip "needs root"
  getent passwd ow38217 >/dev/null || skip "needs the test user ow38217"
  getent group owpanel-sftp >/dev/null || groupadd --system owpanel-sftp
  SCRIPT="${BATS_TEST_TMPDIR}/site-sftp"
  sed "s#/opt/owpanel/lib/#${ROOT}/server/lib/#" "${ROOT}/server/bin/site-sftp" >"$SCRIPT"
  export OWP_SFTP_KEYS_DIR="${BATS_TEST_TMPDIR}/keys"
  export OWP_SITES_ROOT="${BATS_TEST_TMPDIR}/www"
  export OWP_SSHD_DROPIN="${ROOT}/server/templates/ssh/owpanel-sftp.conf"
  mkdir -p "$OWP_SFTP_KEYS_DIR"
  install -d -o root -g ow38217 -m 0750 "${OWP_SITES_ROOT}/ow38217"
  ssh-keygen -q -t ed25519 -N '' -C 'test key' -f "${BATS_TEST_TMPDIR}/id"
  KEY="$(<"${BATS_TEST_TMPDIR}/id.pub")"
}

teardown() {
  [[ "$(id -u)" -eq 0 ]] && getent passwd ow38217 >/dev/null || return 0
  gpasswd --delete ow38217 owpanel-sftp >/dev/null 2>&1 || true
  usermod --password '!' ow38217
}

shadow() {
  getent shadow ow38217 | cut -d: -f2
}

@test "site-sftp turns access on with password and key" {
  run bash -c "printf 'password\tGeheim-Test-123\nkey\t%s\n' '$KEY' | bash '$SCRIPT' --user ow38217 --state on"
  [ "$status" -eq 0 ]
  [ "${lines[-1]}" = '{"ok":true,"script":"site-sftp","enabled":true,"password":true,"keys":1}' ]
  id -nG ow38217 | grep -qw owpanel-sftp
  [[ "$(shadow)" == '$'* ]]
  run cat "${OWP_SFTP_KEYS_DIR}/ow38217"
  [ "$output" = "restrict ${KEY}" ]
  [ "$(stat -c '%U %G %a' "${OWP_SFTP_KEYS_DIR}/ow38217")" = "root ow38217 640" ]
}

@test "site-sftp is repeatable and drops password and keys not sent again" {
  printf 'password\tGeheim-Test-123\nkey\t%s\n' "$KEY" | bash "$SCRIPT" --user ow38217 --state on
  run bash "$SCRIPT" --user ow38217 --state on </dev/null
  [ "$status" -eq 0 ]
  [ "${lines[-1]}" = '{"ok":true,"script":"site-sftp","enabled":true,"password":false,"keys":0}' ]
  [ "$(shadow)" = '!' ]
  [ ! -e "${OWP_SFTP_KEYS_DIR}/ow38217" ]
  id -nG ow38217 | grep -qw owpanel-sftp
}

@test "site-sftp turns access off" {
  printf 'password\tGeheim-Test-123\nkey\t%s\n' "$KEY" | bash "$SCRIPT" --user ow38217 --state on
  run bash "$SCRIPT" --user ow38217 --state off </dev/null
  [ "$status" -eq 0 ]
  [ "${lines[-1]}" = '{"ok":true,"script":"site-sftp","enabled":false}' ]
  ! id -nG ow38217 | grep -qw owpanel-sftp
  [ "$(shadow)" = '!' ]
  [ ! -e "${OWP_SFTP_KEYS_DIR}/ow38217" ]
  run bash "$SCRIPT" --user ow38217 --state off </dev/null
  [ "$status" -eq 0 ]
}

@test "site-sftp rejects bad keys and passwords" {
  local key
  for key in 'from="1.2.3.4" ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI' 'ssh-dss AAAAB3NzaC1kc3M=' 'ssh-ed25519 AAAA$(id)' 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIbroken'; do
    run bash -c "printf 'key\t%s\n' '$key' | bash '$SCRIPT' --user ow38217 --state on"
    [ "$status" -eq 1 ]
    [[ "${lines[-1]}" == *'"error":"invalid_ssh_key"'* ]] || { echo "accepted: $key"; return 1; }
  done
  for password in 'short' 'mit leerzeichen 123' 'umlaut-äöü-12345'; do
    run bash -c "printf 'password\t%s\n' '$password' | bash '$SCRIPT' --user ow38217 --state on"
    [[ "${lines[-1]}" == *'"error":"invalid_password"'* ]] || { echo "accepted: $password"; return 1; }
  done
  run bash -c "printf 'shell\tbash\n' | bash '$SCRIPT' --user ow38217 --state on"
  [[ "${lines[-1]}" == *'"error":"invalid_input"'* ]]
  ! id -nG ow38217 | grep -qw owpanel-sftp
}

@test "site-sftp refuses an unsafe chroot directory" {
  chmod 0770 "${OWP_SITES_ROOT}/ow38217"
  run bash "$SCRIPT" --user ow38217 --state on </dev/null
  [[ "${lines[-1]}" == *'"error":"unsafe_path"'* ]]
}

@test "site-sftp refuses the panel user and invalid input" {
  run bash "$SCRIPT" --user ow10000 --state on </dev/null
  [[ "${lines[-1]}" == *'"error":"reserved_user"'* ]]
  run bash "$SCRIPT" --user root --state on </dev/null
  [[ "${lines[-1]}" == *'"error":"invalid_user"'* ]]
  run bash "$SCRIPT" --user ow38217 --state maybe </dev/null
  [[ "${lines[-1]}" == *'"error":"invalid_arguments"'* ]]
}
