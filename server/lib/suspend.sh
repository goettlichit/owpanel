# shellcheck shell=bash
# Shared by site-suspend and site-unsuspend (E-127). A suspended website
# keeps all its data; what it did before is kept in its state directory,
# so unsuspending brings back exactly that.

OWP_NGINX_SITES="${OWP_NGINX_SITES:-/etc/nginx/owpanel-sites}"
OWP_CRON_DIR="${OWP_CRON_DIR:-/etc/cron.d}"
OWP_SUSPEND_DIR="${OWP_SUSPEND_DIR:-/var/lib/owpanel/suspended}"
OWP_SUSPEND_PAGE_DIR="${OWP_SUSPEND_PAGE_DIR:-/var/lib/owpanel/suspended-page}"
OWP_SUSPEND_SNIPPET="${OWP_SUSPEND_SNIPPET:-/etc/nginx/owpanel/site-suspended.conf}"
readonly OWP_SUSPEND_INCLUDE_LINE="    include /etc/nginx/owpanel/site-suspended.conf; # owpanel-suspended"

usage() {
  owp_die "invalid_arguments" "usage: ${OWP_SCRIPT_NAME} --user <owNNNNN>"
}

# Reads --user and checks it; sets OWP_SUSPEND_USER. Not in a subshell, so
# an error reaches the panel.
# shellcheck disable=SC2034  # read by site-suspend and site-unsuspend
OWP_SUSPEND_USER=""
owp_suspend_args() {
  local user=""
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --user)
        [[ $# -ge 2 ]] || usage
        user="$2"
        shift 2
        ;;
      *) usage ;;
    esac
  done
  [[ -n "$user" ]] || usage
  owp_valid_site_user "$user" || owp_die "invalid_user" "invalid site user"
  ! owp_reserved_site_user "$user" || owp_die "reserved_user" "${user} belongs to the panel"
  # shellcheck disable=SC2034
  OWP_SUSPEND_USER="$user"
}

# The site file with the include after every server_name line, or without
# it. Other lines stay byte for byte.
# Usage: owp_suspend_nginx_with <file> | owp_suspend_nginx_without <file>
owp_suspend_nginx_with() {
  awk -v inc="$OWP_SUSPEND_INCLUDE_LINE" '
    index($0, "# owpanel-suspended") { next }
    { print }
    /^[[:space:]]*server_name[[:space:]]/ { print inc }
  ' "$1"
}

owp_suspend_nginx_without() {
  awk 'index($0, "# owpanel-suspended") == 0' "$1"
}

owp_suspend_pools() {
  local pool
  for pool in /etc/php/*/fpm/pool.d/"owpanel-${1}.conf"; do
    [[ -e "$pool" ]] && printf '%s\n' "$pool"
  done
  return 0
}

# PHP version from a pool path or a kept pool file name ("pool-8.4.conf").
owp_suspend_php_version() {
  local version
  if [[ "$1" == /etc/php/* ]]; then
    version="${1#/etc/php/}"
    version="${version%%/*}"
  else
    version="${1##*/pool-}"
    version="${version%.conf}"
  fi
  [[ "$version" =~ ^[0-9]+\.[0-9]+$ ]] || return 1
  printf '%s\n' "$version"
}
