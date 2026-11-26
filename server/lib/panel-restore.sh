# shellcheck shell=bash
# Restoring the panel from its backup on the Storage Box during a first
# installation (E-099). Used by the installer; needs common.sh and
# install.sh.

readonly OWP_RESTORE_PANEL_ENV="/var/www/ow10000/shared/.env"
readonly OWP_RESTORE_WORKER_SSH="/var/lib/owpanel-worker/ssh"
readonly OWP_RESTORE_STATE="/etc/owpanel/panel.conf"
readonly OWP_RESTORE_DUMP="/var/backups/owpanel/panel/owpanel.sql"

owp_restore_valid_host() {
  [[ ${#1} -le 253 && "$1" =~ ^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$ ]]
}

owp_restore_valid_account() {
  [[ "$1" =~ ^u[0-9]{3,10}(-sub[0-9]{1,4})?$ ]]
}

owp_restore_valid_path() {
  [[ ${#1} -le 200 && "$1" =~ ^[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+)*$ ]]
}

# SSH to the Storage Box with the subaccount password from a file only
# root can read (<work dir>/box-password). sshpass hands it to SSH, so no
# terminal is needed and it never shows up in the process list. No commas:
# restic splits the value of sftp.command at them.
owp_restore_ssh_command() {
  local host="$1" account="$2" work="$3"
  printf '%s' "sshpass -f ${work}/box-password ssh -p 23 -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=${work}/known_hosts -o PubkeyAuthentication=no -o ConnectTimeout=20 ${account}@${host}"
}

# Tests the login before restic runs. Prints "ok", "password" (rejected)
# or "unreachable".
# Usage: owp_restore_probe <host> <account> <work dir>
owp_restore_probe() {
  local status=0
  # shellcheck disable=SC2046 # the command is split into its words on purpose
  $(owp_restore_ssh_command "$1" "$2" "$3") pwd >/dev/null 2>&1 </dev/null || status=$?
  case "$status" in
    5) printf 'password\n' ;;
    255 | 2 | 3 | 6) printf 'unreachable\n' ;;
    *) printf 'ok\n' ;;
  esac
}

# The repository path inside the subaccount. The subaccount only sees its
# own home directory, so "owpanel/<server id>/restic" and the home
# directory itself both mean "restic" there.
owp_restore_repository_path() {
  local path="$1"
  if [[ "$path" =~ (^|/)[0-9a-hjkmnp-tv-z]{26}$ ]]; then
    path="restic"
  elif [[ "$path" =~ (^|/)[0-9a-hjkmnp-tv-z]{26}/(.+)$ ]]; then
    path="${BASH_REMATCH[2]}"
  fi
  printf '%s\n' "$path"
}

# restic over SFTP to the Storage Box.
# Usage: owp_restore_restic <host> <account> <path> <password file> <work dir> <restic arguments...>
owp_restore_restic() {
  local host="$1" account="$2" path="$3" password_file="$4" work="$5"
  shift 5
  RESTIC_PASSWORD_FILE="$password_file" RESTIC_CACHE_DIR="${work}/cache" \
    restic -r "sftp:${account}@${host}:${path}" \
    -o sftp.command="$(owp_restore_ssh_command "$host" "$account" "$work") -s sftp" \
    "$@" </dev/null
}

# Lists the panel backups, newest first, at most ten, as
# "<short id> <time in UTC>" lines.
# Usage: owp_restore_list <host> <account> <path> <password file> <work dir>
owp_restore_list() {
  owp_restore_restic "$@" snapshots --tag panel --json \
    | jq -r 'sort_by(.time) | reverse | .[:10][] | "\(.short_id) \(.time)"' \
    | while read -r id time; do
      [[ "$id" =~ ^[0-9a-f]{8}$ ]] || continue
      printf '%s %s\n' "$id" "$(date -u -d "$time" '+%Y-%m-%d %H:%M')"
    done
}

# Restores one panel backup into a directory.
# Usage: owp_restore_fetch <host> <account> <path> <password file> <work dir> <short id> <target>
owp_restore_fetch() {
  local id="$6" target="$7"
  [[ "$id" =~ ^[0-9a-f]{8}$ ]] || owp_die "invalid_input" "invalid backup id"
  owp_restore_restic "$1" "$2" "$3" "$4" "$5" restore "$id" --tag panel --target "$target"
}

# Checks what was restored: the dump of the database owpanel and .env must
# be there, and the dump may only name its own database.
owp_restore_check() {
  local dir="$1"
  [[ -f "${dir}${OWP_RESTORE_DUMP}" && -f "${dir}${OWP_RESTORE_PANEL_ENV}" ]] || return 1
  ! grep -E '^(USE|CREATE DATABASE)' "${dir}${OWP_RESTORE_DUMP}" | grep -vqF "\`owpanel\`"
}

# Reads one setting from the restored panel.conf, e.g. DOMAIN.
owp_restore_setting() {
  local dir="$1" key="$2"
  [[ -f "${dir}${OWP_RESTORE_STATE}" ]] || return 0
  owp_read_setting "${dir}${OWP_RESTORE_STATE}" "$key"
}

# Puts the worker's SSH key back, so the other servers trust the panel.
# Usage: owp_restore_worker_key <dir> <worker user>
owp_restore_worker_key() {
  local dir="$1" user="$2"
  [[ -f "${dir}${OWP_RESTORE_WORKER_SSH}/id_ed25519" && -f "${dir}${OWP_RESTORE_WORKER_SSH}/id_ed25519.pub" ]] || return 1
  install -o "$user" -g "$user" -m 0600 "${dir}${OWP_RESTORE_WORKER_SSH}/id_ed25519" "${OWP_RESTORE_WORKER_SSH}/id_ed25519"
  install -o "$user" -g "$user" -m 0644 "${dir}${OWP_RESTORE_WORKER_SSH}/id_ed25519.pub" "${OWP_RESTORE_WORKER_SSH}/id_ed25519.pub"
}

# Puts .env back; the installer then keeps it and only updates version,
# time zone and database password.
# Usage: owp_restore_env <dir> <panel user>
owp_restore_env() {
  local dir="$1" user="$2"
  install -o root -g "$user" -m 0640 "${dir}${OWP_RESTORE_PANEL_ENV}" "$OWP_RESTORE_PANEL_ENV"
}

# Imports the panel database and gives the own server the host key and SSH
# port of this new server; its backup connection is set up again from the
# panel. The panel shows which backup it came from (setting
# "panel_restored").
# Usage: owp_restore_database <dir> <ssh port> <short id> <backup time "YYYY-MM-DD HH:MM" UTC>
owp_restore_database() {
  local dir="$1" port="$2" id="$3" time="$4" host_key now
  host_key="$(cut -d' ' -f1-2 /etc/ssh/ssh_host_ed25519_key.pub)"
  [[ "$host_key" =~ ^ssh-ed25519\ [A-Za-z0-9+/=]+$ ]] || owp_die "host_key_invalid" "cannot read the host key of this server"
  [[ "$port" =~ ^[0-9]{1,5}$ ]] || owp_die "ssh_port_unknown" "invalid SSH port"
  [[ "$id" =~ ^[0-9a-f]{8}$ ]] || owp_die "invalid_input" "invalid backup id"
  [[ "$time" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}\ [0-9]{2}:[0-9]{2}$ ]] || owp_die "invalid_input" "invalid backup time"
  now="$(date -u '+%Y-%m-%d %H:%M')"
  mariadb --protocol=socket -e "DROP DATABASE IF EXISTS \`owpanel\`"
  mariadb --protocol=socket --default-character-set=utf8mb4 <"${dir}${OWP_RESTORE_DUMP}" \
    || owp_die "import_failed" "the panel database could not be imported"
  # By address, not by the column hosts_panel (E-192): a dump from an older
  # version has no such column; the migration sets it afterwards.
  mariadb --protocol=socket owpanel \
    -e "UPDATE servers SET host_key = '${host_key}', port = ${port}, backup_ready = 0 WHERE host IN ('127.0.0.1', '::1', 'localhost')"
  # The cache lives in the database (CACHE_STORE=database) and came with the
  # dump; cleared, so settings are read anew, including the note below.
  mariadb --protocol=socket owpanel -e "DELETE FROM cache; DELETE FROM cache_locks"
  mariadb --protocol=socket owpanel -e "REPLACE INTO settings (\`key\`, value, created_at, updated_at) VALUES ('panel_restored', '{\"snapshot\":\"${id}\",\"backup_time\":\"${time}\",\"restored_at\":\"${now}\"}', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
}
