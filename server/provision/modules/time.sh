# shellcheck shell=bash

# Keeps the clock in sync (E-046). Certificates, logs and the firewall timer
# depend on it. An existing NTP client such as chrony stays in place.
provision_time() {
  if [[ "$(timedatectl show --property=NTP --value 2>/dev/null)" != "yes" ]]; then
    owp_apt_install systemd-timesyncd
    timedatectl set-ntp true || owp_die "time_sync_failed" "could not enable time synchronization"
  fi
  owp_log "time zone: $(timedatectl show --property=Timezone --value)"
}
