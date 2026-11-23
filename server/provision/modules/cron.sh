# shellcheck shell=bash

# cron runs the cron jobs of the websites (E-088). Debian installs it with
# the standard system utilities, a minimal installation may not have it.
provision_cron() {
  owp_apt_install cron
  systemctl enable --now cron
}
