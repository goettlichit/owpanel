# shellcheck shell=bash
# shellcheck disable=SC2034  # constants are used by the scripts sourcing this file
# Checks for release archives (E-167): versions, checksum and signature.
# Sourced after common.sh, never executed. get-owpanel keeps its own copy of
# the signature check, because it runs before anything is installed and its
# checksum is published in the guides (E-180).

readonly OWP_RELEASE_REPO="goettlichit/owpanel"
readonly OWP_RELEASE_IDENTITY="owpanel-release"
# The public keys the installer stored; root may add a key, nobody else.
OWP_RELEASE_SIGNERS="${OWP_RELEASE_SIGNERS:-/etc/owpanel/release-signers}"
# Tagged releases only: "1.2.3" or "1.2.3-rc.1", never a test build.
readonly OWP_RELEASE_TAG_RE='^[0-9]{1,4}\.[0-9]{1,4}\.[0-9]{1,4}(-[0-9A-Za-z.]{1,32})?$'

# Whether a version is a tagged release (not "dev-<commit>").
owp_release_tagged() {
  [[ "$1" =~ $OWP_RELEASE_TAG_RE ]]
}

# Whether tagged release $1 is newer than tagged release $2. A pre-release
# comes before its release (1.2.0-rc.1 < 1.2.0), as in semantic versioning.
owp_release_newer() {
  local a="$1" b="$2" core_a core_b pre_a="" pre_b="" first
  [[ "$a" != "$b" ]] || return 1
  core_a="${a%%-*}"
  core_b="${b%%-*}"
  [[ "$a" == *-* ]] && pre_a="${a#*-}"
  [[ "$b" == *-* ]] && pre_b="${b#*-}"
  if [[ "$core_a" != "$core_b" ]]; then
    first="$(printf '%s\n%s\n' "$core_a" "$core_b" | sort -V | head -n 1)"
    [[ "$first" == "$core_b" ]]
    return
  fi
  # Same core: the one without a pre-release is newer.
  [[ -n "$pre_a" ]] || return 0
  [[ -n "$pre_b" ]] || return 1
  first="$(printf '%s\n%s\n' "$pre_a" "$pre_b" | sort -V | head -n 1)"
  [[ "$first" == "$pre_b" ]]
}

# Checks archive against its .sha256 file in the same directory.
# Usage: owp_release_check_sum <archive>
owp_release_check_sum() {
  local dir name
  dir="$(dirname "$1")"
  name="$(basename "$1")"
  [[ -f "${1}.sha256" ]] || return 1
  # The file must name exactly this archive, nothing else.
  [[ "$(awk '{ print $2 }' "${1}.sha256" | sed 's/^\*//')" == "$name" ]] || return 1
  (cd "$dir" && sha256sum --check --strict --status "${name}.sha256")
}

# Checks the signature <archive>.sig against the stored release keys.
# Usage: owp_release_verify <archive>
owp_release_verify() {
  [[ -s "$OWP_RELEASE_SIGNERS" && -f "${1}.sig" ]] || return 1
  ssh-keygen -Y verify -f "$OWP_RELEASE_SIGNERS" -I "$OWP_RELEASE_IDENTITY" -n "$OWP_RELEASE_IDENTITY" \
    -s "${1}.sig" <"$1" >/dev/null 2>&1
}
