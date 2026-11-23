# shellcheck shell=bash
# Shared parts of the restic backups (E-096). Everything lives below
# /etc/owpanel/backup, readable by root only: the SSH key for the Storage
# Box, its host key, the SSH configuration, the repository address and the
# repository password.

# Tests point this elsewhere; sudo drops it.
OWP_BACKUP_DIR="${OWP_BACKUP_DIR:-/etc/owpanel/backup}"
# Database dumps are written here for the length of one backup.
OWP_BACKUP_STAGING="${OWP_BACKUP_STAGING:-/var/backups/owpanel}"
OWP_RESTIC_BIN="${OWP_RESTIC_BIN:-restic}"
# Alias in the SSH configuration; restic connects through it.
# shellcheck disable=SC2034  # used by backup-setup
readonly OWP_BACKUP_HOST_ALIAS="owpanel-backup"

owp_backup_valid_host() {
  [[ ${#1} -le 253 && "$1" =~ ^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$ ]]
}

# Hetzner Storage Box account or sub-account: u123456 or u123456-sub1.
owp_backup_valid_account() {
  [[ "$1" =~ ^u[0-9]{3,10}(-sub[0-9]{1,4})?$ ]]
}

# Relative path on the Storage Box: segments of letters, digits, - and _.
owp_backup_valid_path() {
  [[ ${#1} -le 200 && "$1" =~ ^[A-Za-z0-9_-]+(/[A-Za-z0-9_-]+)*$ ]]
}

owp_backup_configured() {
  [[ -f "${OWP_BACKUP_DIR}/repository" && -f "${OWP_BACKUP_DIR}/repo-password" && -f "${OWP_BACKUP_DIR}/ssh_config" ]]
}

owp_require_backup() {
  owp_backup_configured || owp_die "backup_not_configured" "the backup target is not set up on this server"
  command -v "$OWP_RESTIC_BIN" >/dev/null 2>&1 || owp_die "restic_not_installed" "restic is missing; run the base setup again"
}

# Runs restic against the configured repository. stdout stays with restic.
owp_restic() {
  RESTIC_REPOSITORY="$(<"${OWP_BACKUP_DIR}/repository")" \
    RESTIC_PASSWORD_FILE="${OWP_RESTIC_PASSWORD_FILE:-${OWP_BACKUP_DIR}/repo-password}" \
    RESTIC_CACHE_DIR="${OWP_BACKUP_DIR}/cache" \
    "$OWP_RESTIC_BIN" -o sftp.args="-F ${OWP_BACKUP_DIR}/ssh_config" "$@"
}
