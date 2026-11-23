#!/usr/bin/env bats
# Argument checks of server-ssh (E-155). Changing sshd needs root and is
# tested on a VM.

ROOT="${BATS_TEST_DIRNAME}/.."

setup() {
  sed "s#/opt/owpanel/lib/#${ROOT}/server/lib/#" "${ROOT}/server/bin/server-ssh" >"${BATS_TEST_TMPDIR}/server-ssh"
}

@test "server-ssh takes only --status or --password-login off|on" {
  local args
  for args in "" "--status now" "--password-login" "--password-login maybe" "--password-login off --force" "--root-login off"; do
    # shellcheck disable=SC2086
    run bash "${BATS_TEST_TMPDIR}/server-ssh" $args
    [ "$status" -ne 0 ]
    [[ "${lines[-1]}" == *'"error":"invalid_arguments"'* ]]
  done
}

@test "password login off is checked by sshd and keeps SFTP passwords" {
  grep -q '/usr/sbin/sshd -t' "${ROOT}/server/bin/server-ssh"
  grep -q '"no_key"' "${ROOT}/server/bin/server-ssh"
  grep -q 'Match Group owpanel-sftp' "${ROOT}/server/templates/ssh/owpanel-sftp.conf"
  grep -q 'PasswordAuthentication yes' "${ROOT}/server/templates/ssh/owpanel-sftp.conf"
  grep -q 'server-ssh' "${ROOT}/server/templates/sudoers/owpanel-agent"
}
