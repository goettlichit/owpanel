# shellcheck shell=bash
# Helpers for the installer (E-069). Sourced after common.sh and site.sh,
# never executed. Kept apart from the installer so the tests can load them.

# Release versions are "1.2.3" or "1.2.3-rc.1" for tagged builds and
# "dev-<commit>" for builds from a branch (scripts/build-release).
owp_valid_version() {
  [[ "$1" =~ $OWP_VERSION_RE ]]
}

# One address, no spaces, quotes or control characters. The panel checks the
# address once more when it creates the account.
owp_valid_email() {
  local email="$1"
  [[ "${#email}" -le 254 ]] || return 1
  [[ "$email" =~ ^[A-Za-z0-9.!#%+_-]+@[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?\.[a-z]{2,63}$ ]] || return 1
  [[ "$email" != *..* ]]
}

# First and last names: 1 to 100 characters, letters of any language,
# spaces, hyphens, apostrophes and dots. Nothing a shell or SQL would treat
# specially reaches the panel anyway, because names are passed as separate
# arguments.
owp_valid_person_name() {
  local name="$1"
  [[ "${#name}" -ge 1 && "${#name}" -le 100 ]] || return 1
  [[ "$name" =~ ^[[:alpha:]]([[:alpha:] .\'-]*[[:alpha:].])?$ ]]
}

# Minimum like the panel's password rule (AppServiceProvider): 12 to 255
# characters. Control characters would break the hand-over on stdin.
owp_valid_password() {
  local password="$1"
  [[ "${#password}" -ge 12 && "${#password}" -le 255 ]] || return 1
  [[ ! "$password" =~ [[:cntrl:]] ]]
}

# Addresses the domain resolves to, one per line.
owp_resolve_domain() {
  # Only DNS: /etc/hosts maps the server's own name to 127.0.1.1, and with
  # the host name as panel domain the check failed although DNS was right
  # (2026-11-22).
  getent -s dns ahosts "$1" 2>/dev/null | awk '{print $1}' | sort -u
}

# Global addresses of this server, one per line.
owp_local_addresses() {
  ip -o addr show scope global 2>/dev/null | awk '{split($4, a, "/"); print a[1]}' | sort -u
}

# First global address of the family 4 or 6, empty if there is none. Only
# shown in the panel (E-072); the panel reaches its own server on 127.0.0.1.
owp_public_address() {
  # awk reads everything, so ip never sees a broken pipe under pipefail.
  { ip -o "-${1}" addr show scope global 2>/dev/null || true; } \
    | awk 'NR == 1 {split($4, a, "/"); print a[1]}'
}

# True if at least one address of the domain belongs to this server. Let's
# Encrypt checks the domain over port 80, so a wrong DNS entry would only
# fail later and with a less helpful message.
owp_domain_points_here() {
  local domain="$1" address
  local -a resolved=() own=()
  mapfile -t resolved < <(owp_resolve_domain "$domain")
  mapfile -t own < <(owp_local_addresses)
  [[ "${#resolved[@]}" -gt 0 ]] || return 2
  for address in "${resolved[@]}"; do
    if printf '%s\n' "${own[@]}" | grep -qxF -- "$address"; then
      return 0
    fi
  done
  return 1
}

# 32 random bytes as hex, for database passwords. Hex needs no quoting in
# SQL or .env.
owp_random_hex() {
  od -An -N32 -tx1 /dev/urandom | tr -d ' \n'
}

# Laravel's APP_KEY format: base64 of 32 random bytes.
owp_random_app_key() {
  printf 'base64:%s\n' "$(head -c 32 /dev/urandom | base64 -w0)"
}

# Writes the panel's .env. All values must be validated before; none of them
# can contain quotes, spaces or line breaks.
# Usage: owp_render_env <output> <domain> <version> <timezone> <app key> <db password> <ssh key path>
owp_render_env() {
  local output="$1" domain="$2" version="$3" zone="$4" app_key="$5" db_password="$6" ssh_key="$7"
  cat >"$output" <<EOF
# Written by the OWPanel installer. APP_KEY encrypts sessions and secrets in
# the database: keep it, also when moving the panel.
APP_NAME=OWPanel
APP_ENV=production
APP_KEY=${app_key}
APP_DEBUG=false
APP_URL=https://${domain}

APP_LOCALE=de
APP_FALLBACK_LOCALE=en
APP_MAINTENANCE_DRIVER=file
BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=info

DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=owpanel
DB_USERNAME=owpanel
DB_PASSWORD=${db_password}

SESSION_DRIVER=database
SESSION_LIFETIME=60
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_PATH=/
SESSION_DOMAIN=null

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=3900
CACHE_STORE=database

MAIL_MAILER=log
MAIL_FROM_ADDRESS="panel@${domain}"
MAIL_FROM_NAME="OWPanel"

OWPANEL_TIMEZONE=${zone}
OWPANEL_VERSION=${version}
OWPANEL_SOURCE_URL=https://github.com/goettlichit/owpanel
OWPANEL_SSH_PRIVATE_KEY=${ssh_key}
EOF
}

# Reads KEY=value from an .env or state file without sourcing it.
# Usage: owp_read_setting <file> <KEY>
owp_read_setting() {
  sed -n "s/^${2}=//p" "$1" | tail -n 1
}

# Sets KEY=value in a file that has the key already. Value must be validated.
# Usage: owp_write_setting <file> <KEY> <value>
owp_write_setting() {
  local file="$1" key="$2" value="$3" tmp
  tmp="$(mktemp "$(dirname "$file")/.setting.XXXXXX")"
  awk -v key="$key" -v value="$value" '
    index($0, key "=") == 1 { print key "=" value; done = 1; next }
    { print }
    END { if (!done) print key "=" value }
  ' "$file" >"$tmp"
  chmod --reference="$file" "$tmp"
  chown --reference="$file" "$tmp"
  mv -f "$tmp" "$file"
}
