#!/usr/bin/env bats
# Argument checks of server-upgrade (E-154). Installing updates needs root
# and apt and is tested on a VM.

ROOT="${BATS_TEST_DIRNAME}/.."

setup() {
  sed "s#/opt/owpanel/lib/#${ROOT}/server/lib/#" "${ROOT}/server/bin/server-upgrade" >"${BATS_TEST_TMPDIR}/server-upgrade"
}

@test "server-upgrade wants exactly --refresh or --install" {
  local args
  for args in "" "--upgrade" "--refresh --install" "--install now" "-y"; do
    # shellcheck disable=SC2086
    run bash "${BATS_TEST_TMPDIR}/server-upgrade" $args
    [ "$status" -ne 0 ]
    [[ "${lines[-1]}" == *'"error":"invalid_arguments"'* ]]
  done
}

@test "server-upgrade keeps changed configuration files and waits for the apt lock" {
  grep -q -- '--force-confold' "${ROOT}/server/bin/server-upgrade"
  grep -q -- 'DPkg::Lock::Timeout' "${ROOT}/server/bin/server-upgrade"
  grep -q 'DEBIAN_FRONTEND=noninteractive' "${ROOT}/server/bin/server-upgrade"
}
