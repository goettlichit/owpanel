# shellcheck shell=bash
# shellcheck disable=SC2154  # TEMPLATES comes from server-provision

# Automatic updates with unattended-upgrades (E-047). The daily run is
# started by Debian's timers apt-daily.timer and apt-daily-upgrade.timer.
provision_updates() {
  local file
  owp_apt_install unattended-upgrades

  for file in 52owpanel-unattended-upgrades 21owpanel-auto-upgrades; do
    owp_apply_config "${TEMPLATES}/apt/${file}" "/etc/apt/apt.conf.d/${file}" \
      0644 unattended-upgrades restart -- apt-config dump \
      || owp_die "updates_config_invalid" "apt rejected ${file}"
  done

  # While the installer has paused the automatic updates, the timers are
  # only enabled; they start when it ends or with the next boot.
  local now="--now"
  [[ "${OWP_UPDATES_PAUSED:-0}" != 1 ]] || now=""
  # shellcheck disable=SC2086 # empty when paused
  systemctl enable $now apt-daily.timer apt-daily-upgrade.timer \
    || owp_die "updates_timer_failed" "could not enable the apt timers"
}
