#!/usr/bin/env bats
# Tests for server/bin/server-scripts-update (E-167). Installing needs root
# and runs setup-agent; that part is tested on a VM.

bats_require_minimum_version 1.5.0

ROOT="${BATS_TEST_DIRNAME}/.."

setup() {
  SCRIPT="${BATS_TEST_TMPDIR}/server-scripts-update"
  sed "s#/opt/owpanel/lib/#${ROOT}/server/lib/#" "${ROOT}/server/bin/server-scripts-update" >"$SCRIPT"
  # The functions alone, without running main.
  FUNCTIONS="${BATS_TEST_TMPDIR}/functions.sh"
  sed '$d' "$SCRIPT" >"$FUNCTIONS"
  export OWP_INSTALLED_VERSION_FILE="${BATS_TEST_TMPDIR}/VERSION"
  WORK="${BATS_TEST_TMPDIR}/work"
  mkdir -p "$WORK"
}

# A signing key, the keys file and a signed archive, as the release
# workflow builds them.
make_signed_archive() {
  local dir="${BATS_TEST_TMPDIR}/release"
  mkdir -p "${dir}/owpanel-1.2.3/server"
  printf '1.2.3\n' >"${dir}/owpanel-1.2.3/VERSION"
  tar -czf "${dir}/owpanel-1.2.3.tar.gz" -C "$dir" owpanel-1.2.3
  ssh-keygen -q -t ed25519 -N '' -C test -f "${dir}/key"
  printf 'owpanel-release %s\n' "$(cut -d' ' -f1,2 "${dir}/key.pub")" >"${dir}/signers"
  ssh-keygen -q -Y sign -f "${dir}/key" -n owpanel-release "${dir}/owpanel-1.2.3.tar.gz"
  ARCHIVE="${dir}/owpanel-1.2.3.tar.gz"
  export OWP_RELEASE_SIGNERS="${dir}/signers"
}

# Reads stdin the way the script does: signature, then archive.
receive() {
  # shellcheck source=/dev/null
  source "$FUNCTIONS"
  owp_init "server-scripts-update"
  SIGNED=0
  read_signature "${WORK}/a.tar.gz.sig"
  read_archive "${WORK}/a.tar.gz"
  printf 'signed=%s\n' "$SIGNED"
  if ((SIGNED == 1)); then
    owp_release_verify "${WORK}/a.tar.gz" && echo "valid" || echo "invalid"
  fi
}

@test "server-scripts-update wants --version with a version" {
  local args
  for args in "" "--version" "--status" "--version 1.2.3 extra" "--archive x"; do
    # shellcheck disable=SC2086
    run bash "$SCRIPT" $args
    [ "$status" -ne 0 ]
    [[ "${lines[-1]}" == *'"error":"invalid_arguments"'* ]]
  done
  for args in "1.2" "v1.2.3" "dev-xyz" "1.2.3;id" "../1.2.3"; do
    run bash "$SCRIPT" --version "$args"
    [ "$status" -ne 0 ]
    [[ "${lines[-1]}" == *'"error":"invalid_version"'* ]]
  done
}

@test "a signature followed by the archive arrives intact and verifies" {
  make_signed_archive
  run bash -c "$(declare -f receive); FUNCTIONS='$FUNCTIONS' WORK='$WORK' receive" < <(cat "${ARCHIVE}.sig" "$ARCHIVE")
  [ "$status" -eq 0 ]
  [[ "$output" == *"signed=1"* ]]
  [[ "$output" == *"valid"* && "$output" != *"invalid"* ]]
  cmp "$ARCHIVE" "${WORK}/a.tar.gz"
}

@test "a changed archive or another key fails the signature" {
  make_signed_archive
  { cat "${ARCHIVE}.sig"; printf 'x'; cat "$ARCHIVE"; } >"${BATS_TEST_TMPDIR}/changed"
  run bash -c "$(declare -f receive); FUNCTIONS='$FUNCTIONS' WORK='$WORK' receive" <"${BATS_TEST_TMPDIR}/changed"
  [[ "$output" == *"invalid"* ]]

  ssh-keygen -q -t ed25519 -N '' -C other -f "${BATS_TEST_TMPDIR}/other"
  printf 'owpanel-release %s\n' "$(cut -d' ' -f1,2 "${BATS_TEST_TMPDIR}/other.pub")" >"$OWP_RELEASE_SIGNERS"
  run bash -c "$(declare -f receive); FUNCTIONS='$FUNCTIONS' WORK='$WORK' receive" < <(cat "${ARCHIVE}.sig" "$ARCHIVE")
  [[ "$output" == *"invalid"* ]]
}

@test "unsigned is read as such" {
  make_signed_archive
  run bash -c "$(declare -f receive); FUNCTIONS='$FUNCTIONS' WORK='$WORK' receive" < <(printf 'unsigned\n'; cat "$ARCHIVE")
  [ "$status" -eq 0 ]
  [[ "$output" == *"signed=0"* ]]
  cmp "$ARCHIVE" "${WORK}/a.tar.gz"
}

@test "anything else on stdin is refused" {
  local input
  for input in "" "hello\n" "-----BEGIN SSH SIGNATURE-----\nnot base64!\n" "-----BEGIN SSH SIGNATURE-----\nU1NIU0lH\n"; do
    run bash -c "$(declare -f receive); FUNCTIONS='$FUNCTIONS' WORK='$WORK' receive" < <(printf '%b' "$input")
    [ "$status" -ne 0 ]
    [[ "$output" == *'"error":"invalid_input"'* ]]
  done
  # A signature without an archive after it.
  make_signed_archive
  run bash -c "$(declare -f receive); FUNCTIONS='$FUNCTIONS' WORK='$WORK' receive" <"${ARCHIVE}.sig"
  [ "$status" -ne 0 ]
  [[ "$output" == *'"error":"invalid_input"'* ]]
}

@test "never an older release, the same or a newer one is fine" {
  # shellcheck source=/dev/null
  source "$FUNCTIONS"
  owp_init "server-scripts-update"
  printf '1.2.3\n' >"$OWP_INSTALLED_VERSION_FILE"
  check_order 1.2.3
  check_order 1.2.4
  check_order 2.0.0
  check_order dev-0123abc
  run check_order 1.2.2
  [ "$status" -ne 0 ]
  [[ "$output" == *'"error":"not_newer"'* ]]

  # From a test build or without a version anything goes.
  printf 'dev-0123abc\n' >"$OWP_INSTALLED_VERSION_FILE"
  check_order 0.1.0
  rm -f "$OWP_INSTALLED_VERSION_FILE"
  check_order 0.1.0
}

# shellcheck disable=SC2016  # the patterns are literal
@test "unsigned archives need a test build and the permission of root" {
  grep -q '\[\[ "$version" == dev-\* \]\] || owp_die "signature_required"' "${ROOT}/server/bin/server-scripts-update"
  grep -q 'owp_die "unsigned_not_allowed"' "${ROOT}/server/bin/server-scripts-update"
  grep -q 'write_unsigned_permission' "${ROOT}/install"
}

# shellcheck disable=SC2016  # the patterns are literal
@test "only server/ and VERSION are unpacked and setup-agent keeps the key" {
  grep -q '"owpanel-${version}/VERSION" "owpanel-${version}/server"' "${ROOT}/server/bin/server-scripts-update"
  grep -q 'setup-agent" --keep-key' "${ROOT}/server/bin/server-scripts-update"
}

@test "setup-agent takes either a key file or --keep-key" {
  local script="${ROOT}/server/provision/setup-agent" args
  for args in "" "--keep-key --public-key-file x" "--public-key-file x --keep-key" "--other"; do
    # shellcheck disable=SC2086
    run bash "$script" $args
    [ "$status" -ne 0 ]
    [[ "${lines[-1]}" == *'"error":"invalid_arguments"'* ]]
  done
}

@test "server-scripts-update is in the sudo rules" {
  grep -q '/opt/owpanel/bin/server-scripts-update' "${ROOT}/server/templates/sudoers/owpanel-agent"
}
