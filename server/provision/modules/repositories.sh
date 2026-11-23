# shellcheck shell=bash
# shellcheck disable=SC2154  # TEMPLATES comes from server-provision

# Adds nginx.org (stable) and packages.sury.org (PHP) as package sources
# (E-042). The keys are downloaded on every run, checked against the pinned
# fingerprints and refreshed, because both projects extend their keys from
# time to time. A key with another fingerprint stops the run.

readonly NGINX_KEY_URL="https://nginx.org/keys/nginx_signing.key"
# nginx_signing.key holds three keys: the original one from 2011 and the
# signing keys 2 and 3 from 2024. Debian 13 is signed with key 2.
readonly NGINX_KEY_FPRS=(
  573BFD6B3D8FBC641079A6ABABF5BD827BD9BF62
  8540A6F18833A80E9C1653A42FD21310B49F6B46
  9E9BE90EACBCDE69FE9B204CBCDCD8A38D88A2B3
)
readonly SURY_KEY_URL="https://packages.sury.org/php/apt.gpg"
readonly SURY_KEY_FPRS=(
  15058500A0235D97F5D10063B188E2B695BD4743
)
readonly KEYRINGS="/etc/apt/keyrings"

provision_repositories() {
  # Debian's nginx packages use another layout (www-data, sites-enabled,
  # snippets). Switching an existing server is not supported.
  if dpkg-query -W -f='${Status}' nginx-common 2>/dev/null | grep -q 'install ok installed'; then
    owp_die "debian_nginx_installed" \
      "Debian's nginx is installed; OWPanel needs a fresh server with nginx from nginx.org"
  fi

  local before after
  owp_apt_install ca-certificates curl gnupg
  install -d -o root -g root -m 0755 "$KEYRINGS"
  before="$(repository_state)"
  owp_install_repo_key "$NGINX_KEY_URL" "${KEYRINGS}/owpanel-nginx.gpg" "${NGINX_KEY_FPRS[@]}"
  owp_install_repo_key "$SURY_KEY_URL" "${KEYRINGS}/owpanel-sury-php.gpg" "${SURY_KEY_FPRS[@]}"

  install -o root -g root -m 0644 "${TEMPLATES}/apt/owpanel-nginx.sources" \
    /etc/apt/sources.list.d/owpanel-nginx.sources
  install -o root -g root -m 0644 "${TEMPLATES}/apt/owpanel-sury-php.sources" \
    /etc/apt/sources.list.d/owpanel-sury-php.sources
  install -o root -g root -m 0644 "${TEMPLATES}/apt/owpanel-nginx.pref" \
    /etc/apt/preferences.d/owpanel-nginx

  # A changed key or source must be read before any install; keys are
  # extended from time to time, and apt fails on a stale one.
  after="$(repository_state)"
  if [[ "$before" != "$after" ]]; then
    owp_apt_update || owp_die "repository_update_failed" "apt could not read the package sources"
  else
    owp_log "unchanged: package sources"
  fi
}

# Prints a checksum over all files this module manages. On a fresh server
# none of them exists yet; cat fails then, which pipefail would turn into
# an abort.
repository_state() {
  { cat "${KEYRINGS}"/owpanel-*.gpg /etc/apt/sources.list.d/owpanel-*.sources \
    /etc/apt/preferences.d/owpanel-nginx 2>/dev/null || true; } | sha256sum
}
