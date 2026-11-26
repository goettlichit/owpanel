#!/usr/bin/env bats
# Tests for the release signature (E-167): the key in get-owpanel matches
# the file release-signers, and get-owpanel accepts only matching
# signatures. Each test makes its own throwaway key; no key is stored.

ROOT="${BATS_TEST_DIRNAME}/.."

key_lines() {
  grep -v '^#' "$1" | grep -v '^[[:space:]]*$' || true
}

# Loads the functions of get-owpanel with RELEASE_SIGNERS set to the given
# lines.
load_get_owpanel() {
  local signers="$1" script="${BATS_TEST_TMPDIR}/get-owpanel.sh"
  RELEASE_SIGNERS="$signers"
  # The constants are read-only, so the script is loaded once per test.
  declare -F verify_signature >/dev/null && return 0
  # Leaves out the constant, which may span several lines, and main "$@".
  awk '
    /^readonly RELEASE_SIGNERS=\047/ { skip = 1 }
    skip { if (/\047$/ && !/^readonly RELEASE_SIGNERS=\047$/) skip = 0; next }
    /^main "\$@"$/ { next }
    { print }
  ' "${ROOT}/get-owpanel" >"$script"
  # shellcheck disable=SC1090
  source "$script"
  WORK="$BATS_TEST_TMPDIR"
}

setup() {
  command -v ssh-keygen >/dev/null || skip "ssh-keygen is missing"
  KEY="${BATS_TEST_TMPDIR}/key"
  ssh-keygen -q -t ed25519 -N '' -C owpanel-release -f "$KEY"
  SIGNERS="owpanel-release namespaces=\"owpanel-release\" $(cut -d' ' -f1,2 "${KEY}.pub")"
  ARCHIVE="${BATS_TEST_TMPDIR}/owpanel-1.0.0.tar.gz"
  printf 'archive\n' >"$ARCHIVE"
}

# Text between the quotes of RELEASE_SIGNERS in get-owpanel.
embedded_signers() {
  awk '
    /^readonly RELEASE_SIGNERS=\047/ { found = 1; sub(/^readonly RELEASE_SIGNERS=\047/, "") }
    found { if (sub(/\047$/, "")) { print; exit } print }
  ' "${ROOT}/get-owpanel" | grep -v '^[[:space:]]*$' || true
}

@test "get-owpanel contains the same key lines as release-signers" {
  grep -q "^readonly RELEASE_SIGNERS='" "${ROOT}/get-owpanel"
  [ "$(embedded_signers)" = "$(key_lines "${ROOT}/release-signers")" ]
}

@test "the release archive contains release-signers" {
  grep -q 'release-signers' "${ROOT}/scripts/build-release"
}

@test "a release with a valid signature is accepted" {
  ssh-keygen -q -Y sign -f "$KEY" -n owpanel-release "$ARCHIVE" </dev/null
  load_get_owpanel "$SIGNERS"
  MODE="release"
  run verify_signature "$ARCHIVE"
  [ "$status" -eq 0 ]
  [[ "$output" == *"Signature checked"* ]]
}

@test "a changed archive or another key is refused" {
  ssh-keygen -q -Y sign -f "$KEY" -n owpanel-release "$ARCHIVE" </dev/null
  printf 'changed\n' >>"$ARCHIVE"
  load_get_owpanel "$SIGNERS"
  MODE="dev"
  run verify_signature "$ARCHIVE"
  [ "$status" -eq 1 ]
  [[ "$output" == *"invalid signature"* ]]

  ssh-keygen -q -t ed25519 -N '' -f "${BATS_TEST_TMPDIR}/other"
  printf 'archive\n' >"$ARCHIVE"
  rm -f "${ARCHIVE}.sig"
  ssh-keygen -q -Y sign -f "${BATS_TEST_TMPDIR}/other" -n owpanel-release "$ARCHIVE" </dev/null
  run verify_signature "$ARCHIVE"
  [ "$status" -eq 1 ]
}

@test "a signature for another purpose is refused" {
  ssh-keygen -q -Y sign -f "$KEY" -n file "$ARCHIVE"
  load_get_owpanel "$SIGNERS"
  MODE="release"
  run verify_signature "$ARCHIVE"
  [ "$status" -eq 1 ]
}

@test "a release needs a signature and a key" {
  load_get_owpanel "$SIGNERS"
  MODE="release"
  run verify_signature "$ARCHIVE"
  [ "$status" -eq 1 ]
  [[ "$output" == *"has no signature"* ]]

  ssh-keygen -q -Y sign -f "$KEY" -n owpanel-release "$ARCHIVE" </dev/null
  load_get_owpanel ""
  MODE="release"
  run verify_signature "$ARCHIVE"
  [ "$status" -eq 1 ]
  [[ "$output" == *"contains no release key"* ]]
}

@test "a test build without signature or key is installed with a warning" {
  load_get_owpanel "$SIGNERS"
  MODE="dev"
  run verify_signature "$ARCHIVE"
  [ "$status" -eq 0 ]
  [[ "$output" == *"not signed"* ]]

  load_get_owpanel ""
  MODE="dev"
  run verify_signature "$ARCHIVE"
  [ "$status" -eq 0 ]
  [[ "$output" == *"contains no release key"* ]]
}

# The installation guides publish the checksum of get-owpanel (E-071); a
# change of the script must update all of them.
@test "the guides show the current checksum of get-owpanel" {
  local sum file
  sum="$(sha256sum "${ROOT}/get-owpanel" | cut -d' ' -f1)"
  for file in README.md README.en.md website/content/de/docs/02-installation.md \
    website/content/en/docs/02-installation.md; do
    grep -q "^echo \"[0-9a-f]\{64\}  get-owpanel\" | sha256sum -c$" "${ROOT}/${file}"
    run grep -c "^echo \"${sum}  get-owpanel\" | sha256sum -c$" "${ROOT}/${file}"
    [ "$output" = "1" ] || { echo "${file} shows an old checksum of get-owpanel"; return 1; }
  done
}

@test "release notes are the version's section of the changelog" {
  local changelog="${BATS_TEST_TMPDIR}/CHANGELOG.md"
  printf '# Changelog\n\n## [Unreleased]\n\n## [1.2.3] - 2026-11-26\n\nIntro.\n\n### Fixed\n- One.\n\n## [1.2.2] - 2026-11-20\n\n### Added\n- Old.\n' >"$changelog"
  run bash "${ROOT}/scripts/release-notes" 1.2.3 "$changelog"
  [ "$status" -eq 0 ]
  [ "$output" = "$(printf 'Intro.\n\n### Fixed\n- One.')" ]
  run bash "${ROOT}/scripts/release-notes" 1.2.4 "$changelog"
  [ "$status" -ne 0 ]
  run bash "${ROOT}/scripts/release-notes" "1.2.3; id" "$changelog"
  [ "$status" -eq 2 ]
}

@test "every released version has a changelog section" {
  run bash "${ROOT}/scripts/release-notes" 0.2.1 "${ROOT}/CHANGELOG.md"
  [ "$status" -eq 0 ]
}
