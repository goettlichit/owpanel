# shellcheck shell=bash
# shellcheck disable=SC2154  # TEMPLATES comes from server-provision

provision_fail2ban() {
  owp_apt_install fail2ban python3-systemd

  # The jail for hidden files needs its log before fail2ban starts (E-174).
  [[ -e /var/log/nginx/owpanel-hidden.log ]] || install -o root -g adm -m 0640 /dev/null /var/log/nginx/owpanel-hidden.log
  owp_apply_config "${TEMPLATES}/fail2ban/owpanel-hidden.conf" \
    /etc/fail2ban/filter.d/owpanel-hidden.conf 0644 fail2ban restart -- true \
    || owp_die "fail2ban_config_invalid" "fail2ban rejected the filter owpanel-hidden"
  owp_apply_config "${TEMPLATES}/fail2ban/owpanel.local" \
    /etc/fail2ban/jail.d/owpanel.local 0644 fail2ban restart -- fail2ban-client --test \
    || owp_die "fail2ban_config_invalid" "fail2ban rejected owpanel.local"

  systemctl enable --now fail2ban
}
