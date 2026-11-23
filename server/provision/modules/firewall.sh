# shellcheck shell=bash
# shellcheck disable=SC2154  # TEMPLATES comes from server-provision

# nftables with an own table inet owpanel (E-044). Runs as the last module, so
# the 60 second confirmation window is not used up by package installs. The
# SSH ports are read from the running configuration, so a custom port cannot
# lock out the panel or the admin.
provision_firewall() {
  local rc=0

  # Two firewall tools would fight over the same packets.
  if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q '^Status: active'; then
    owp_die "ufw_active" "ufw is active; OWPanel manages the firewall with nftables"
  fi

  # jq reads the hits of the blocklists (E-170).
  owp_apt_install nftables jq

  owp_firewall_ssh_ports

  owp_tmpdir
  # Own rules stay as confirmed in the panel (E-090).
  owp_firewall_render "${OWP_TMPDIR}/firewall.nft" "$OWP_FW_SSH_PORTS" "$OWP_FW_RULES"

  local unit=/etc/systemd/system/owpanel-firewall.service
  if ! cmp -s "${TEMPLATES}/systemd/owpanel-firewall.service" "$unit"; then
    install -o root -g root -m 0644 "${TEMPLATES}/systemd/owpanel-firewall.service" "$unit"
    systemctl daemon-reload
  fi
  systemctl enable owpanel-firewall.service >/dev/null 2>&1 \
    || owp_die "firewall_unit_failed" "could not enable owpanel-firewall.service"

  # Loads saved blocklists at boot, if the panel set any (E-170).
  OWP_BL_UNIT_TEMPLATE="${TEMPLATES}/systemd/owpanel-blocklist.service" owp_blocklist_unit

  # The list of own rules is already saved, so it is not passed on.
  owp_firewall_apply "${OWP_TMPDIR}/firewall.nft" || rc=$?
  [[ "$rc" -eq 0 || "$rc" -eq 10 ]] || owp_die "firewall_failed" "firewall change failed"
  # shellcheck disable=SC2034  # read by server-provision
  [[ "$rc" -eq 0 ]] || OWP_FIREWALL_PENDING=1
}
