# shellcheck shell=bash
# shellcheck disable=SC2154  # TEMPLATES comes from server-provision

# Limits how long the systemd journal keeps entries (E-092). It holds SSH
# sign-ins, fail2ban bans and the output of cron jobs, often with IP
# addresses. NGINX logs are already rotated after 14 days by Debian.
provision_journal() {
  local target=/etc/systemd/journald.conf.d/owpanel.conf
  install -d -o root -g root -m 0755 /etc/systemd/journald.conf.d
  if cmp -s "${TEMPLATES}/systemd/journald-owpanel.conf" "$target"; then
    owp_log "journal retention unchanged"
    return 0
  fi
  install -o root -g root -m 0644 "${TEMPLATES}/systemd/journald-owpanel.conf" "$target"
  systemctl restart systemd-journald || owp_die "journal_failed" "systemd-journald did not restart"
  owp_log "journal keeps entries for 14 days"
}
