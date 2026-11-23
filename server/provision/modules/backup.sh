# shellcheck shell=bash

# restic for the backups of websites and the panel (E-096), rsync to copy
# restored files back as the site user (E-098). The target is set up
# later from the panel with backup-setup.
provision_backup() {
  owp_apt_install restic rsync
  owp_log "restic $(restic version 2>/dev/null | awk '{print $2}')"
}
