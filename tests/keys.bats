#!/usr/bin/env bats
# Tests for the pinned repository keys. Generates throwaway keys, runs
# without root and without network.

ROOT="${BATS_TEST_DIRNAME}/.."

setup_file() {
  export GNUPGHOME="${BATS_FILE_TMPDIR}/gnupg"
  mkdir -m 0700 "$GNUPGHOME"
  gpg --batch --quiet --passphrase '' --quick-gen-key "Test A <a@example.invalid>" ed25519 sign,cert 1y
  gpg --batch --quiet --passphrase '' --quick-gen-key "Test B <b@example.invalid>" ed25519 sign,cert 1y
  gpg --batch --quiet --passphrase '' --faked-system-time 20200101T000000 \
    --quick-gen-key "Old <old@example.invalid>" ed25519 sign,cert 1d
  gpg --batch --quiet --armor --export "Test A" >"${BATS_FILE_TMPDIR}/a.asc"
  gpg --batch --quiet --armor --export "Test A" "Test B" >"${BATS_FILE_TMPDIR}/ab.asc"
  gpg --batch --quiet --export "Old" >"${BATS_FILE_TMPDIR}/old.gpg"
  FPR_A="$(gpg --with-colons --list-keys "Test A" | awk -F: '$1=="fpr"{print $10; exit}')"
  FPR_B="$(gpg --with-colons --list-keys "Test B" | awk -F: '$1=="fpr"{print $10; exit}')"
  FPR_OLD="$(gpg --with-colons --list-keys "Old" | awk -F: '$1=="fpr"{print $10; exit}')"
  export FPR_A FPR_B FPR_OLD
}

setup() {
  # shellcheck source=server/lib/common.sh
  source "${ROOT}/server/lib/common.sh"
  DEST="${BATS_TEST_TMPDIR}/keyring.gpg"
}

keys_in() {
  gpg --homedir "$(mktemp -d)" --batch --with-colons --show-keys "$1" 2>/dev/null \
    | awk -F: '$1=="pub"{getline; print $10}'
}

@test "installs the key with the pinned fingerprint" {
  owp_export_pinned_key "${BATS_FILE_TMPDIR}/a.asc" "$DEST" "$FPR_A"
  [ "$(keys_in "$DEST")" = "$FPR_A" ]
}

@test "exports only the pinned key from a file with several keys" {
  owp_export_pinned_key "${BATS_FILE_TMPDIR}/ab.asc" "$DEST" "$FPR_B"
  [ "$(keys_in "$DEST")" = "$FPR_B" ]
}

@test "exports all pinned keys that are present, and nothing else" {
  owp_export_pinned_key "${BATS_FILE_TMPDIR}/ab.asc" "$DEST" "$FPR_A" "$FPR_B"
  [ "$(keys_in "$DEST" | sort)" = "$(printf '%s\n' "$FPR_A" "$FPR_B" | sort)" ]
}

@test "skips pinned keys that are missing from the file" {
  owp_export_pinned_key "${BATS_FILE_TMPDIR}/a.asc" "$DEST" "$FPR_B" "$FPR_A"
  [ "$(keys_in "$DEST")" = "$FPR_A" ]
}

@test "leaves out an expired key next to a valid one" {
  cat "${BATS_FILE_TMPDIR}/old.gpg" >"${BATS_TEST_TMPDIR}/mixed.gpg"
  gpg --batch --quiet --export "Test A" >>"${BATS_TEST_TMPDIR}/mixed.gpg"
  run owp_export_pinned_key "${BATS_TEST_TMPDIR}/mixed.gpg" "$DEST" "$FPR_OLD" "$FPR_A"
  [ "$status" -eq 0 ]
  [ "$(keys_in "$DEST")" = "$FPR_A" ]
}

@test "rejects a key with another fingerprint" {
  run owp_export_pinned_key "${BATS_FILE_TMPDIR}/a.asc" "$DEST" "$FPR_B"
  [ "$status" -eq 1 ]
  [[ "${lines[-1]}" == *'"error":"repository_key_mismatch"'* ]]
  [ ! -e "$DEST" ]
}

@test "rejects an expired key" {
  run owp_export_pinned_key "${BATS_FILE_TMPDIR}/old.gpg" "$DEST" "$FPR_OLD"
  [ "$status" -eq 1 ]
  [[ "${lines[-1]}" == *'"error":"repository_key_expired"'* ]]
  [ ! -e "$DEST" ]
}

@test "rejects a subkey fingerprint" {
  local sub
  gpg --batch --quiet --passphrase '' --quick-add-key "$FPR_A" cv25519 encr 1y
  gpg --batch --quiet --armor --export "$FPR_A" >"${BATS_TEST_TMPDIR}/withsub.asc"
  sub="$(gpg --with-colons --list-keys "$FPR_A" | awk -F: '$1=="sub"{getline; print $10; exit}')"
  [ -n "$sub" ]
  run owp_export_pinned_key "${BATS_TEST_TMPDIR}/withsub.asc" "$DEST" "$sub"
  [ "$status" -eq 1 ]
  [[ "${lines[-1]}" == *'"error":"repository_key_mismatch"'* ]]
}

@test "rejects broken key files and malformed fingerprints" {
  printf 'not a key\n' >"${BATS_TEST_TMPDIR}/junk"
  run owp_export_pinned_key "${BATS_TEST_TMPDIR}/junk" "$DEST" "$FPR_A"
  [ "$status" -eq 1 ]
  [[ "${lines[-1]}" == *'"error":"repository_key_'* ]]
  run owp_export_pinned_key "${BATS_FILE_TMPDIR}/a.asc" "$DEST" "${FPR_A,,}"
  [[ "${lines[-1]}" == *'"error":"invalid_fingerprint"'* ]]
  run owp_export_pinned_key "${BATS_FILE_TMPDIR}/a.asc" "$DEST" "${FPR_A:0:16}"
  [[ "${lines[-1]}" == *'"error":"invalid_fingerprint"'* ]]
}

@test "the pinned fingerprints in the module are well-formed" {
  local module="${ROOT}/server/provision/modules/repositories.sh"
  # Every line inside the fingerprint arrays is exactly one fingerprint.
  run awk '/_KEY_FPRS=\(/{f=1; next} f && /^\)/{f=0} f' "$module"
  [ "${#lines[@]}" -eq 4 ]
  for line in "${lines[@]}"; do
    [[ "$line" =~ ^\ \ [0-9A-F]{40}$ ]] || { echo "bad: $line"; return 1; }
  done
  grep -q '^readonly NGINX_KEY_URL="https://' "${ROOT}/server/provision/modules/repositories.sh"
  grep -q '^readonly SURY_KEY_URL="https://' "${ROOT}/server/provision/modules/repositories.sh"
}

@test "apt sources use the OWPanel keyrings and HTTPS" {
  local file
  for file in "${ROOT}"/server/templates/apt/*.sources; do
    grep -q '^URIs: https://' "$file"
    grep -q '^Suites: trixie$' "$file"
    grep -Eq '^Signed-By: /etc/apt/keyrings/owpanel-[a-z-]+\.gpg$' "$file"
  done
}
