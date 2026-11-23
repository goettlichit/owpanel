# shellcheck shell=bash
# shellcheck disable=SC2154  # TEMPLATES comes from server-provision

# SFTP access of the websites (E-089). Prepares the group, the key directory
# and the sshd drop-in; site-sftp adds or removes the website users. The
# group name OWP_SFTP_GROUP comes from site.sh.

provision_sftp() {
  if ! getent group "$OWP_SFTP_GROUP" >/dev/null; then
    groupadd --system "$OWP_SFTP_GROUP"
    owp_log "created group ${OWP_SFTP_GROUP}"
  fi
  install -d -o root -g root -m 0755 /etc/ssh/owpanel-sftp

  # sshd_config of Debian includes sshd_config.d at the top; an older or
  # changed file without the Include would silently ignore the drop-in.
  if ! grep -Eqi '^[[:space:]]*Include[[:space:]]+/etc/ssh/sshd_config\.d/\*\.conf' /etc/ssh/sshd_config; then
    owp_warn "sshd_config does not include sshd_config.d; SFTP for websites will not work"
  fi
  if sshd -T 2>/dev/null | grep -Eqi '^(allowusers|allowgroups) '; then
    owp_warn "sshd uses AllowUsers or AllowGroups, the group ${OWP_SFTP_GROUP} must be permitted there"
  fi

  owp_apply_config "${TEMPLATES}/ssh/owpanel-sftp.conf" \
    /etc/ssh/sshd_config.d/owpanel-sftp.conf 0644 ssh reload -- sshd -t \
    || owp_die "sftp_config_invalid" "sshd rejected owpanel-sftp.conf"
}
