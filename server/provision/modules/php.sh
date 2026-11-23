# shellcheck shell=bash
# shellcheck disable=SC2154  # TEMPLATES comes from server-provision

readonly OWP_PHP_EXTENSIONS=(fpm cli mysql curl gd intl mbstring xml zip bcmath)

# PHP comes from packages.sury.org (E-042). OWP_PHP_VERSION runs the panel and
# the tools and is required; every further version in OWP_PHP_VERSIONS is
# installed for websites as soon as sury.org has a final release of it, not
# an alpha, beta or release candidate (E-172). Websites get their own pools
# from site-create. The package's www pool is replaced by one without any
# access, see E-039. The command php stays on OWP_PHP_VERSION. A further
# version that does not start only gives a warning and is switched off, so
# it is not offered; the base setup goes on (2026-11-23).
provision_php() {
  local v

  for v in "${OWP_PHP_VERSIONS[@]}"; do
    if [[ "$v" == "$OWP_PHP_VERSION" ]]; then
      php_version_setup "$v" || owp_die "php_start_failed" "PHP-FPM ${v} does not start"
      continue
    fi
    owp_php_installed "$v" || php_release_available "$v" || continue
    if ! php_version_setup "$v"; then
      owp_warn "PHP-FPM ${v} does not start; websites keep the other versions (see journalctl -u php${v}-fpm)"
      systemctl disable --now "php${v}-fpm" >/dev/null 2>&1 || true
    fi
  done

  # A newer php-cli takes over the alternative "php" by its priority.
  update-alternatives --quiet --set php "/usr/bin/php${OWP_PHP_VERSION}" \
    || owp_die "php_version_mismatch" "could not point php to ${OWP_PHP_VERSION}"
  v="$(owp_php_version)" || owp_die "php_version_unknown" "could not determine the PHP version"
  [[ "$v" == "$OWP_PHP_VERSION" ]] \
    || owp_die "php_version_mismatch" "php points to ${v}, expected ${OWP_PHP_VERSION}"
}

# Installs one PHP version with its extensions, replaces the package's www
# pool and starts its PHP-FPM. The pool comes first, so a broken one from an
# earlier run does not keep PHP-FPM from starting. Returns 1 if PHP-FPM does
# not run.
php_version_setup() {
  local v="$1"
  owp_apt_install "${OWP_PHP_EXTENSIONS[@]/#/php${v}-}" || return 1
  owp_tmpdir
  owp_render "${TEMPLATES}/php/www-pool.conf" "${OWP_TMPDIR}/www-${v}.conf" "VERSION=${v}" || return 1
  owp_apply_config "${OWP_TMPDIR}/www-${v}.conf" "/etc/php/${v}/fpm/pool.d/www.conf" \
    0644 "php${v}-fpm" reload -- "php-fpm${v}" -t || return 1
  systemctl enable --now "php${v}-fpm" || return 1
  systemctl is-active --quiet "php${v}-fpm"
}

# Whether apt offers a final release of a PHP version. Pre-releases of sury.org
# carry ~alpha, ~beta or ~rc in the version, e.g. 8.6.0~rc5-1+0~20261105.1.
php_release_available() {
  local candidate
  candidate="$(apt-cache policy "php${1}-fpm" 2>/dev/null | awk '/Candidate:/ { print $2 }')"
  if [[ -z "$candidate" || "$candidate" == "(none)" ]]; then
    owp_warn "PHP ${1} is not offered by the package sources yet; websites keep the other versions"
    return 1
  fi
  if [[ "$candidate" =~ ~(alpha|beta|rc) ]]; then
    owp_warn "PHP ${1} is only available as pre-release ${candidate}; it is installed with the final release"
    return 1
  fi
}
