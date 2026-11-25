#!/usr/bin/env bats
# Tests for server/bin/panel-update and server/lib/release.sh (E-167,
# E-185). Downloading and installing need root, systemd and GitHub and are
# tested on a VM.

bats_require_minimum_version 1.5.0

ROOT="${BATS_TEST_DIRNAME}/.."

setup() {
  SCRIPT="${BATS_TEST_TMPDIR}/panel-update"
  sed "s#/opt/owpanel/lib/#${ROOT}/server/lib/#" "${ROOT}/server/bin/panel-update" >"$SCRIPT"
  export OWP_UPDATE_DIR="${BATS_TEST_TMPDIR}/update"
  mkdir -p "$OWP_UPDATE_DIR"
}

load_release() {
  # shellcheck source=server/lib/common.sh
  source "${ROOT}/server/lib/common.sh"
  # shellcheck source=server/lib/release.sh
  source "${ROOT}/server/lib/release.sh"
}

# A signing key, a signed archive and the keys file, as built by the release
# workflow (ssh-keygen -Y sign, namespace owpanel-release).
make_signed_archive() {
  local dir="${BATS_TEST_TMPDIR}/release"
  mkdir -p "$dir"
  ssh-keygen -q -t ed25519 -N '' -C test -f "${dir}/key"
  printf 'owpanel-release %s\n' "$(cut -d' ' -f1,2 "${dir}/key.pub")" >"${dir}/signers"
  printf 'archive' >"${dir}/owpanel-1.2.3.tar.gz"
  (cd "$dir" && sha256sum owpanel-1.2.3.tar.gz >owpanel-1.2.3.tar.gz.sha256)
  ssh-keygen -q -Y sign -f "${dir}/key" -n owpanel-release "${dir}/owpanel-1.2.3.tar.gz"
  ARCHIVE="${dir}/owpanel-1.2.3.tar.gz"
  export OWP_RELEASE_SIGNERS="${dir}/signers"
}

@test "panel-update wants --version with a release or --status" {
  local args
  for args in "" "--update" "--version" "--status now" "--version 1.2.3 --status" "--version 1.2.3 extra" "--fetch-archive now"; do
    # shellcheck disable=SC2086
    run bash "$SCRIPT" $args
    [ "$status" -ne 0 ]
    [[ "${lines[-1]}" == *'"error":"invalid_arguments"'* ]]
  done
}

@test "panel-update takes only tagged releases" {
  local version
  for version in "dev-0123abc" "1.2" "v1.2.3" "1.2.3;id" "../1.2.3" "1.2.3-" "1.2.3-rc_1"; do
    run bash "$SCRIPT" --version "$version"
    [ "$status" -ne 0 ]
    [[ "${lines[-1]}" == *'"error":"invalid_version"'* ]]
  done
}

@test "status without an update is idle" {
  run bash "$SCRIPT" --status
  [ "$status" -eq 0 ]
  [ "${lines[-1]}" = '{"ok":true,"script":"panel-update","state":"idle","version":null,"from":null,"started_at":null,"finished_at":null,"error":null}' ]
}

@test "status reports a finished update and drops odd values" {
  printf 'state=failed\nversion=1.2.3\nfrom=1.2.2\nstarted_at=2026-11-24T20:00:00Z\nfinished_at=2026-11-24T20:03:10Z\nerror=install_failed\n' >"${OWP_UPDATE_DIR}/status"
  run bash "$SCRIPT" --status
  [ "${lines[-1]}" = '{"ok":true,"script":"panel-update","state":"failed","version":"1.2.3","from":"1.2.2","started_at":"2026-11-24T20:00:00Z","finished_at":"2026-11-24T20:03:10Z","error":"install_failed"}' ]

  printf 'state=odd"\nversion=1.2.3"}\nfrom=\nstarted_at=yesterday\nerror=Bad Code\n' >"${OWP_UPDATE_DIR}/status"
  run bash "$SCRIPT" --status
  [ "${lines[-1]}" = '{"ok":true,"script":"panel-update","state":"idle","version":null,"from":null,"started_at":null,"finished_at":null,"error":null}' ]
}

@test "newer compares releases, pre-releases come before their release" {
  load_release
  owp_release_newer 1.2.4 1.2.3
  owp_release_newer 1.10.0 1.9.9
  owp_release_newer 2.0.0 1.99.99
  owp_release_newer 1.2.0 1.2.0-rc.2
  owp_release_newer 1.2.0-rc.2 1.2.0-rc.1
  owp_release_newer 1.2.0-rc.1 1.1.9
  run ! owp_release_newer 1.2.3 1.2.3
  run ! owp_release_newer 1.2.3 1.2.4
  run ! owp_release_newer 1.9.9 1.10.0
  run ! owp_release_newer 1.2.0-rc.1 1.2.0
}

@test "tagged accepts releases, not test builds" {
  load_release
  owp_release_tagged 0.1.0
  owp_release_tagged 1.2.3-rc.1
  run ! owp_release_tagged dev-0123abc
  run ! owp_release_tagged 1.2
}

@test "a signed archive passes checksum and signature" {
  load_release
  make_signed_archive
  owp_release_check_sum "$ARCHIVE"
  owp_release_verify "$ARCHIVE"
}

@test "a changed archive fails checksum and signature" {
  load_release
  make_signed_archive
  printf 'changed' >"$ARCHIVE"
  run ! owp_release_check_sum "$ARCHIVE"
  run ! owp_release_verify "$ARCHIVE"
}

@test "a signature by another key or without stored keys fails" {
  load_release
  make_signed_archive
  ssh-keygen -q -t ed25519 -N '' -C other -f "${BATS_TEST_TMPDIR}/other"
  printf 'owpanel-release %s\n' "$(cut -d' ' -f1,2 "${BATS_TEST_TMPDIR}/other.pub")" >"$OWP_RELEASE_SIGNERS"
  run ! owp_release_verify "$ARCHIVE"
  : >"$OWP_RELEASE_SIGNERS"
  run ! owp_release_verify "$ARCHIVE"
  rm -f "${ARCHIVE}.sig"
  run ! owp_release_verify "$ARCHIVE"
}

@test "a checksum file naming another archive fails" {
  load_release
  make_signed_archive
  sed -i 's/owpanel-1.2.3/owpanel-9.9.9/' "${ARCHIVE}.sha256"
  run ! owp_release_check_sum "$ARCHIVE"
}

# shellcheck disable=SC2016  # the patterns are literal
@test "panel-update only downloads from the project's releases over https" {
  grep -q 'https://github.com/${OWP_RELEASE_REPO}/releases/download/v${version}' "${ROOT}/server/bin/panel-update"
  grep -q -- "--proto '=https' --proto-redir '=https'" "${ROOT}/server/bin/panel-update"
  grep -q 'readonly OWP_RELEASE_REPO="goettlichit/owpanel"' "${ROOT}/server/lib/release.sh"
}

# shellcheck disable=SC2016  # the patterns are literal
@test "the update runs detached and keeps worker and scheduler running on failure" {
  grep -q 'systemd-run --unit="$OWP_UPDATE_UNIT"' "${ROOT}/server/bin/panel-update"
  grep -q -- '--no-reboot --skip-dns-check' "${ROOT}/server/lib/panel-update-run.sh"
  grep -q 'systemctl start owpanel-queue.service owpanel-scheduler.timer' "${ROOT}/server/lib/panel-update-run.sh"
  grep -q 'readonly WAIT_SECONDS=600' "${ROOT}/server/lib/panel-update-run.sh"
}

@test "panel-update is in the sudo rules" {
  grep -q '/opt/owpanel/bin/panel-update' "${ROOT}/server/templates/sudoers/owpanel-agent"
}
