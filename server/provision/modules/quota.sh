# shellcheck shell=bash

# Turns on journaled user quotas for the file system that holds /var/www, so
# the panel can limit the files of a website (E-126). Only ext4; on other
# file systems the panel just measures. /etc/fstab is checked before it is
# used and the old version comes back when anything fails, so a broken
# line can never stop the next boot.
provision_quota() {
  # shellcheck source=server/lib/quota.sh
  source "${OWP_ROOT}/lib/quota.sh"
  local mp fstype options backup next
  # On a fresh Debian, /var/www does not exist yet (nginx comes later), and
  # findmnt --target fails for a missing path (2026-11-22).
  [[ -d "$OWP_QUOTA_ROOT" ]] || install -d -m 0755 "$OWP_QUOTA_ROOT"
  if ! mp="$(owp_quota_mountpoint)" || ! fstype="$(findmnt -n -o FSTYPE --target "$OWP_QUOTA_ROOT")"; then
    owp_warn "the file system of ${OWP_QUOTA_ROOT} is unknown: storage is only measured"
    return 0
  fi
  if [[ "$fstype" != ext4 ]]; then
    owp_log "user quotas need ext4, ${mp} is ${fstype}: storage is only measured"
    return 0
  fi

  owp_apt_install quota
  if owp_quota_active; then
    owp_log "user quotas are on for ${mp}"
    return 0
  fi

  options="$(findmnt --fstab -n -o OPTIONS "$mp" 2>/dev/null || true)"
  if [[ -z "$options" ]]; then
    owp_warn "${mp} is not in /etc/fstab: storage is only measured"
    return 0
  fi

  if [[ ",${options}," != *",usrjquota=aquota.user,"* ]]; then
    backup="$(mktemp /etc/fstab.owpanel.XXXXXX)"
    next="$(mktemp /etc/fstab.owpanel-next.XXXXXX)"
    cp -p /etc/fstab "$backup"
    # Only this one line changes (its fields joined by single spaces);
    # all other lines stay byte for byte.
    awk -v mp="$mp" '
      /^[[:space:]]*#/ || NF < 4 || $2 != mp { print; next }
      { $4 = $4 ",usrjquota=aquota.user,jqfmt=vfsv1"; print }
    ' /etc/fstab >"$next"
    if ! findmnt --verify --tab-file "$next" >/dev/null 2>&1; then
      rm -f "$next" "$backup"
      owp_warn "the changed /etc/fstab did not pass findmnt --verify; left unchanged"
      return 0
    fi
    install -o root -g root -m 0644 "$next" /etc/fstab
    rm -f "$next"
    if ! mount -o remount "$mp"; then
      install -o root -g root -m 0644 "$backup" /etc/fstab
      mount -o remount "$mp" || true
      rm -f "$backup"
      owp_warn "${mp} could not be mounted with quotas; /etc/fstab is back as before"
      return 0
    fi
    rm -f "$backup"
    owp_log "/etc/fstab: user quotas for ${mp}"
  fi

  # Counts what each user has today and turns the limits on. -m: the file
  # system stays mounted read-write.
  if ! quotacheck -cum "$mp" || ! quotaon -u "$mp"; then
    owp_warn "user quotas could not be turned on for ${mp}: storage is only measured"
    return 0
  fi
  owp_log "user quotas are on for ${mp}"
}
