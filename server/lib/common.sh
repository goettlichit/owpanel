# shellcheck shell=bash
# shellcheck disable=SC2034  # constants are used by the scripts sourcing this file
# Shared helpers for all OWPanel server scripts. Sourced, never executed.

readonly OWP_ROOT="/opt/owpanel"
# Release versions: "1.2.3", "1.2.3-rc.1" or "dev-<commit>" (scripts/build-release).
readonly OWP_VERSION_RE='^([0-9]{1,4}\.[0-9]{1,4}\.[0-9]{1,4}(-[0-9A-Za-z.]{1,32})?|dev-[0-9a-f]{7,40})$'
readonly OWP_AGENT_USER="owpanel-agent"
readonly OWP_AGENT_HOME="/var/lib/owpanel-agent"
readonly OWP_DEBIAN_VERSION="13"
readonly OWP_DEBIAN_CODENAME="trixie"
# PHP comes from packages.sury.org, NGINX from nginx.org (E-042).
# The panel and the tools run on OWP_PHP_VERSION, which is also the default
# for new websites. OWP_PHP_VERSIONS are the versions websites may use
# (E-172); older ones are not offered on purpose.
readonly OWP_PHP_VERSION="8.5"
readonly OWP_PHP_VERSIONS=("8.5" "8.6")
# nginx.org packages run NGINX as this user.
readonly OWP_WEB_USER="nginx"

OWP_SCRIPT_NAME="unknown"
OWP_RESULT_SENT=0
OWP_PUBLIC_KEY=""
OWP_TMPDIR=""
# More paths removed on exit, such as the database dumps of a backup.
OWP_CLEANUP=()
OWP_APT_UPDATED=0
# 1 while the automatic updates are paused by this run.
OWP_APT_PAUSED=0
# "<user>:<KiB>" while site-restore has lifted a quota (lib/quota.sh).
OWP_QUOTA_RESTORE=""

# Sets a known environment and guarantees a JSON result as last output line.
owp_init() {
  OWP_SCRIPT_NAME="$1"
  export PATH="/usr/sbin:/usr/bin:/sbin:/bin"
  export LC_ALL="C.UTF-8"
  umask 022
  trap owp_on_exit EXIT
}

owp_on_exit() {
  local rc=$?
  [[ -z "$OWP_TMPDIR" ]] || rm -rf "$OWP_TMPDIR"
  local path
  for path in "${OWP_CLEANUP[@]}"; do
    rm -rf -- "$path"
  done
  owp_apt_resume_updates
  if [[ -n "$OWP_QUOTA_RESTORE" ]] && declare -F owp_quota_restore >/dev/null; then
    owp_quota_restore
  fi
  if [[ "$rc" -ne 0 && "$OWP_RESULT_SENT" -eq 0 ]]; then
    owp_result_error "unexpected_error"
  fi
  return "$rc"
}

owp_log() {
  printf '%s\n' "$*"
}

owp_warn() {
  printf 'warning: %s\n' "$*" >&2
}

# Optional argument: extra JSON members built only from validated values,
# e.g. '"tls":true'.
# shellcheck disable=SC2120  # the argument is optional
owp_result_ok() {
  OWP_RESULT_SENT=1
  printf '{"ok":true,"script":"%s"%s}\n' "$OWP_SCRIPT_NAME" "${1:+,$1}"
}

# Error codes are fixed snake_case strings. The panel translates them.
owp_result_error() {
  OWP_RESULT_SENT=1
  printf '{"ok":false,"script":"%s","error":"%s"}\n' "$OWP_SCRIPT_NAME" "$1"
}

# Usage: owp_die <error_code> <message>
# The installer sets OWP_COLOR_ERROR and OWP_COLOR_RESET for a terminal.
owp_die() {
  printf '%serror: %s%s\n' "${OWP_COLOR_ERROR:-}" "$2" "${OWP_COLOR_RESET:-}" >&2
  owp_result_error "$1"
  exit 1
}

# One lock for all scripts that change the server, so that nginx and PHP-FPM
# are never reconfigured by two runs at once. Usage: owp_lock <seconds>
owp_lock() {
  exec 9>/run/owpanel.lock
  flock -w "$1" 9 || owp_die "already_running" "another OWPanel script is running"
}

# Private temporary directory, removed on exit. Not usable in a subshell.
owp_tmpdir() {
  [[ -n "$OWP_TMPDIR" ]] || OWP_TMPDIR="$(mktemp -d)"
}

# Prints the installed PHP CLI version, e.g. 8.5.
owp_php_version() {
  local version
  version="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null)" || return 1
  [[ "$version" =~ ^[0-9]+\.[0-9]+$ ]] || return 1
  printf '%s\n' "$version"
}

# Whether a PHP version is installed for websites (its PHP-FPM exists).
owp_php_installed() {
  [[ -x "/usr/sbin/php-fpm${1}" ]]
}

# Whether a PHP version may be used by websites at all.
owp_php_allowed() {
  local v
  for v in "${OWP_PHP_VERSIONS[@]}"; do
    [[ "$v" == "$1" ]] && return 0
  done
  return 1
}

owp_require_root() {
  [[ "$(id -u)" -eq 0 ]] || owp_die "root_required" "this script must run as root"
}

# Reads os-release as data. The file is not sourced on purpose. The optional
# argument is only used by the tests.
# shellcheck disable=SC2120
owp_check_os() {
  local file="${1:-/etc/os-release}" id version
  [[ -r "$file" ]] || owp_die "unsupported_os" "cannot read ${file}"
  id="$(sed -n 's/^ID="\{0,1\}\([^"]*\)"\{0,1\}$/\1/p' "$file")"
  version="$(sed -n 's/^VERSION_ID="\{0,1\}\([^"]*\)"\{0,1\}$/\1/p' "$file")"
  if [[ "$id" != "debian" || "$version" != "$OWP_DEBIAN_VERSION" ]]; then
    owp_die "unsupported_os" "Debian ${OWP_DEBIAN_VERSION} is required"
  fi
}

# Stores the normalized "type blob" of a valid Ed25519 key in OWP_PUBLIC_KEY.
# The optional comment is dropped, so nothing else can reach authorized_keys.
# Must not run in a subshell, otherwise owp_die loses its JSON result.
owp_read_public_key() {
  local file="$1" pattern
  local -a lines=()
  pattern='^(ssh-ed25519 AAAAC3NzaC1lZDI1NTE5[A-Za-z0-9+/]{48})( [A-Za-z0-9@._-]{1,128})?$'
  [[ -f "$file" && ! -L "$file" ]] || owp_die "invalid_public_key" "public key file not found"
  mapfile -t lines <"$file"
  [[ "${#lines[@]}" -eq 1 ]] || owp_die "invalid_public_key" "expected exactly one line"
  [[ "${lines[0]}" =~ $pattern ]] || owp_die "invalid_public_key" "expected an Ed25519 public key"
  OWP_PUBLIC_KEY="${BASH_REMATCH[1]}"
}

# Extracts unique TCP ports from `sshd -T` output on stdin.
owp_parse_ssh_ports() {
  local key value port
  while read -r key value _; do
    case "${key,,}" in
      port) port="$value" ;;
      listenaddress) port="${value##*:}" ;;
      *) continue ;;
    esac
    if [[ "$port" =~ ^[1-9][0-9]{0,4}$ ]] && ((port <= 65535)); then
      printf '%s\n' "$port"
    fi
  done | sort -nu
}

# How long apt waits for the package lock. A fresh server installs its
# first security updates through unattended-upgrades right after booting,
# which can take well over five minutes.
readonly OWP_APT_LOCK_TIMEOUT=1800

# Pauses Debian's automatic updates while this run installs packages. On a
# fresh server apt-daily and unattended-upgrades start right after booting
# and would hold the package locks for many minutes. unattended-upgrades
# stops at a safe point on SIGTERM; dpkg --configure -a finishes whatever
# it left. The run that paused them starts the timers again when it ends
# (owp_on_exit); their missed runs follow soon after (Persistent=true).
# Scripts started by that run see OWP_UPDATES_PAUSED=1 and leave the timers
# alone, so the installer keeps them stopped until its own end, or until
# the restart it offers (OWP_KEEP_UPDATES_PAUSED=1: enabled timers start
# with the next boot).
owp_apt_pause_updates() {
  [[ "${OWP_UPDATES_PAUSED:-0}" != 1 ]] || return 0
  OWP_APT_PAUSED=1
  export OWP_UPDATES_PAUSED=1
  systemctl stop apt-daily.timer apt-daily-upgrade.timer 2>/dev/null || true
  if systemctl is-active --quiet apt-daily.service apt-daily-upgrade.service 2>/dev/null \
    || pgrep -f '/usr/bin/unattended-upgrade' >/dev/null 2>&1; then
    owp_log "pausing the automatic updates; they continue afterwards"
    systemctl stop apt-daily.service apt-daily-upgrade.service 2>/dev/null || true
    pkill -TERM -f '/usr/bin/unattended-upgrade' 2>/dev/null || true
  fi
}

owp_apt_resume_updates() {
  ((OWP_APT_PAUSED == 1)) || return 0
  OWP_APT_PAUSED=0
  [[ "${OWP_KEEP_UPDATES_PAUSED:-0}" != 1 ]] || return 0
  systemctl start apt-daily.timer apt-daily-upgrade.timer 2>/dev/null || true
}

# Waits until no other package tool runs, after pausing the automatic
# updates; usually a few seconds until unattended-upgrades has finished its
# current package. Not by process name "unattended-upgr": the kernel cuts
# names to 15 characters, and unattended-upgrade-shutdown, which runs all
# the time, has the same short name.
owp_apt_wait() {
  local waited=0 noted=0
  owp_apt_pause_updates
  while ((waited < OWP_APT_LOCK_TIMEOUT)); do
    if ! systemctl is-active --quiet apt-daily.service apt-daily-upgrade.service 2>/dev/null \
      && ! pgrep -f '/usr/bin/unattended-upgrade' >/dev/null 2>&1 \
      && ! pgrep -x apt-get >/dev/null 2>&1 \
      && ! pgrep -x apt >/dev/null 2>&1 \
      && ! pgrep -x dpkg >/dev/null 2>&1; then
      if ((noted == 1)); then
        owp_log "package tools finished after ${waited} seconds"
        dpkg --configure -a >/dev/null 2>&1 || owp_warn "dpkg --configure -a reported errors"
      fi
      return 0
    fi
    if ((noted == 0)); then
      owp_log "waiting for another package tool to finish"
      noted=1
    fi
    sleep 5
    waited=$((waited + 5))
  done
  owp_warn "other package tools still run after $((OWP_APT_LOCK_TIMEOUT / 60)) minutes, trying anyway"
}

owp_apt_update() {
  owp_apt_wait
  apt-get -o DPkg::Lock::Timeout="$OWP_APT_LOCK_TIMEOUT" update && OWP_APT_UPDATED=1
}

# True if every given package is installed.
owp_packages_installed() {
  local pkg
  for pkg in "$@"; do
    dpkg-query -W -f='${Status}' "$pkg" 2>/dev/null | grep -qx 'install ok installed' || return 1
  done
}

# Installs missing packages only. Package lists are refreshed once per run
# and only when something has to be installed. Installed packages are not
# upgraded here; updates are a separate step.
owp_apt_install() {
  if owp_packages_installed "$@"; then
    owp_log "installed: $*"
    return 0
  fi
  if [[ "$OWP_APT_UPDATED" -eq 0 ]]; then
    owp_apt_update || owp_warn "apt update reported errors, trying to install anyway"
    OWP_APT_UPDATED=1
  fi
  # A package tool may start in between, e.g. apt-daily on its timer: wait
  # and try again, three times at most.
  local attempt
  for attempt in 1 2 3; do
    owp_apt_wait
    if DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout="$OWP_APT_LOCK_TIMEOUT" \
      -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold \
      install -y --no-install-recommends "$@"; then
      return 0
    fi
    if ((attempt < 3)); then
      owp_log "package installation failed, trying again (${attempt}/3)"
      sleep 10
    fi
  done
  owp_die "package_install_failed" "could not install: $*"
}

# Brings the whole system up to date: package lists and every pending
# update (dist-upgrade, so new dependencies such as a new kernel come
# along). Used by the installer on a first installation; changed config
# files of packages keep the local version.
owp_apt_upgrade() {
  local attempt
  for attempt in 1 2 3; do
    owp_apt_wait
    if apt-get -o DPkg::Lock::Timeout="$OWP_APT_LOCK_TIMEOUT" update \
      && DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout="$OWP_APT_LOCK_TIMEOUT" \
        -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold \
        dist-upgrade -y; then
      OWP_APT_UPDATED=1
      return 0
    fi
    if ((attempt < 3)); then
      owp_log "system update failed, trying again (${attempt}/3)"
      sleep 10
    fi
  done
  owp_die "system_update_failed" "could not install the pending updates"
}

# Usage: owp_service_action <service> <reload|restart>
owp_service_action() {
  case "$2" in
    reload) systemctl reload-or-restart "$1" ;;
    restart) systemctl restart "$1" ;;
    *) return 1 ;;
  esac
}

# Installs a config file, validates it and applies it. On any failure the
# previous version is restored and the function returns 1.
# Usage: owp_apply_config <src> <dest> <mode> <service> <reload|restart> -- <validate command...>
owp_apply_config() {
  local src="$1" dest="$2" mode="$3" service="$4" action="$5" backup=""
  shift 6

  if [[ -f "$dest" ]] && cmp -s "$src" "$dest"; then
    owp_log "unchanged: ${dest}"
    return 0
  fi

  if [[ -e "$dest" ]]; then
    backup="$(mktemp)" || return 1
    if ! cp -p "$dest" "$backup"; then
      rm -f "$backup"
      return 1
    fi
  fi

  if install -o root -g root -m "$mode" "$src" "$dest" \
    && "$@" \
    && owp_service_action "$service" "$action"; then
    [[ -z "$backup" ]] || rm -f "$backup"
    owp_log "updated: ${dest}"
    return 0
  fi

  owp_warn "rolling back ${dest}"
  if [[ -n "$backup" ]]; then
    cp -p "$backup" "$dest"
    rm -f "$backup"
  else
    rm -f "$dest"
  fi
  owp_service_action "$service" "$action" || owp_warn "${service} did not recover"
  return 1
}

# Removes a config file, validates the remaining configuration and applies it.
# On failure the file is restored and the function returns 1.
# Usage: owp_remove_config <dest> <service> <reload|restart> -- <validate command...>
owp_remove_config() {
  local dest="$1" service="$2" action="$3" backup
  shift 4

  if [[ ! -e "$dest" && ! -L "$dest" ]]; then
    owp_log "already absent: ${dest}"
    return 0
  fi

  backup="$(mktemp)" || return 1
  if ! cp -p "$dest" "$backup"; then
    rm -f "$backup"
    return 1
  fi

  if rm -f "$dest" && "$@" && owp_service_action "$service" "$action"; then
    rm -f "$backup"
    owp_log "removed: ${dest}"
    return 0
  fi

  owp_warn "restoring ${dest}"
  cp -p "$backup" "$dest"
  rm -f "$backup"
  owp_service_action "$service" "$action" || owp_warn "${service} did not recover"
  return 1
}

# Exports the pinned OpenPGP keys from a downloaded key file into an apt
# keyring. Every pinned key that is present and valid is exported, nothing
# else. Fails if none is usable, so a replaced or stale key never reaches
# apt. Projects sign with several keys, nginx.org currently with three.
# Must not run in a subshell.
# Usage: owp_export_pinned_key <key file> <destination> <fingerprint>...
owp_export_pinned_key() {
  local file="$1" dest="$2" home listing fpr validity found=0 expired=0
  local -a usable=()
  shift 2
  [[ $# -gt 0 ]] || owp_die "invalid_fingerprint" "no fingerprint given"
  for fpr in "$@"; do
    [[ "$fpr" =~ ^[0-9A-F]{40}$ ]] || owp_die "invalid_fingerprint" "invalid fingerprint"
  done

  home="$(mktemp -d)"
  listing="$(gpg --homedir "$home" --batch --with-colons --show-keys "$file" 2>/dev/null)" || {
    rm -rf "$home"
    owp_die "repository_key_invalid" "cannot read the key file"
  }
  for fpr in "$@"; do
    # Primary key line (pub), followed by its fingerprint line (fpr).
    validity="$(awk -F: -v fpr="$fpr" '
      $1 == "pub" { v = $2; want = 1; next }
      $1 == "fpr" && want { if ($10 == fpr) { print v; exit } want = 0 }
    ' <<<"$listing")"
    [[ -n "$validity" ]] || continue
    found=$((found + 1))
    if [[ "$validity" == "e" || "$validity" == "r" ]]; then
      expired=$((expired + 1))
      owp_warn "key ${fpr} is expired or revoked and is left out"
      continue
    fi
    usable+=("$fpr")
  done

  if [[ "$found" -eq 0 ]]; then
    rm -rf "$home"
    owp_die "repository_key_mismatch" "the key file contains none of the pinned keys"
  fi
  if [[ "${#usable[@]}" -eq 0 ]]; then
    rm -rf "$home"
    owp_die "repository_key_expired" "all pinned keys are expired or revoked"
  fi
  gpg --homedir "$home" --batch --quiet --import "$file" 2>/dev/null
  gpg --homedir "$home" --batch --export "${usable[@]}" >"${home}/export.gpg"
  # Root owns the result anyway; the plain mode keeps the tests runnable.
  install -m 0644 "${home}/export.gpg" "$dest"
  rm -rf "$home"
}

# Downloads a repository key over HTTPS and installs the pinned keys.
# Usage: owp_install_repo_key <https url> <destination> <fingerprint>...
owp_install_repo_key() {
  local url="$1" dest="$2"
  shift 2
  owp_tmpdir
  curl --fail --silent --show-error --location --proto '=https' --tlsv1.2 \
    --max-time 60 --output "${OWP_TMPDIR}/key" "$url" \
    || owp_die "repository_key_download_failed" "could not download ${url}"
  owp_export_pinned_key "${OWP_TMPDIR}/key" "$dest" "$@"
}

# Accepts zone names such as Europe/Berlin, America/Argentina/Buenos_Aires
# or UTC, and only if the zone file exists. Nothing is read from the input
# beyond this check.
owp_valid_timezone() {
  local zone="$1"
  [[ "$zone" =~ ^[A-Z][A-Za-z]+(/[A-Z][A-Za-z0-9_+-]+){0,2}$ ]] || return 1
  [[ "$zone" != *..* ]] || return 1
  # Some zones such as UTC are links within zoneinfo, which is fine.
  local real
  real="$(realpath -e "/usr/share/zoneinfo/${zone}" 2>/dev/null)" || return 1
  [[ "$real" == /usr/share/zoneinfo/* && -f "$real" ]]
}

# Prints the newest installed kernel if it is newer than the running one,
# which means a reboot is needed (E-076). Debian itself rarely creates
# /run/reboot-required, so this compares /lib/modules with uname -r.
# OWP_MODULES_DIR exists for the tests; sudo drops it.
owp_pending_kernel() {
  local dir="${OWP_MODULES_DIR:-/lib/modules}" running newest
  running="$(uname -r)"
  newest="$(find "$dir" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' 2>/dev/null \
    | grep -E '^[0-9][A-Za-z0-9._+-]*$' | sort -V | tail -n 1 || true)"
  [[ -n "$newest" && "$newest" != "$running" ]] || return 0
  [[ "$(printf '%s\n%s\n' "$running" "$newest" | sort -V | tail -n 1)" == "$newest" ]] || return 0
  printf '%s\n' "$newest"
}

# True if the server should be restarted: a newer kernel is installed or a
# package asked for it in /run/reboot-required.
owp_reboot_required() {
  [[ -e "${OWP_STATUS_REBOOT_FILE:-/run/reboot-required}" || -n "$(owp_pending_kernel)" ]]
}
