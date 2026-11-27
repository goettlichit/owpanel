# shellcheck shell=bash
# Join mode of the installer (E-166, E-196): sets up this server as a
# website server of an existing panel. Sourced by install, never executed.
#
#   bash get-owpanel --join panel.example.de --via 10.0.0.2
#
# The panel is reached by its name, so its certificate is checked, but over
# its private address (--via), so token and archive never cross the
# internet. The one-time code of the panel comes from get-owpanel in a file
# (OWP_JOIN_TOKEN_FILE) or is asked for; it never appears in arguments.
#
# The server gets the agent with the panel's key, the base services and a
# firewall that is open to the public on SSH only and to the private
# networks on every port. Then it registers at the panel, which pins its
# host key and confirms the firewall over SSH; without that the firewall
# goes back after 60 seconds.

readonly JOIN_ROLE_FILE="/etc/owpanel/role"
readonly JOIN_FW_PENDING="/run/owpanel/firewall/pending.nft"

JOIN_DOMAIN=""
JOIN_VIA=""
JOIN_HEADERS=""
JOIN_NAME=""
JOIN_KEY_FILE=""
JOIN_PRIVATE_IP=""
JOIN_PRIVATE_NETS=""
JOIN_PUBLIC_IPV4=""
JOIN_PUBLIC_IPV6=""

join_cleanup() {
  [[ -z "$JOIN_HEADERS" ]] || rm -f "$JOIN_HEADERS"
  [[ -z "$JOIN_KEY_FILE" ]] || rm -f "$JOIN_KEY_FILE"
  # Also after an abort, the automatic updates go on.
  owp_apt_resume_updates
}

# HTTPS to the panel by name over its private address, with the code.
join_curl() {
  curl --fail --silent --show-error --proto '=https' --tlsv1.2 --max-time 300 \
    --resolve "${JOIN_DOMAIN}:443:${JOIN_VIA}" -H "@${JOIN_HEADERS}" "$@"
}

join_check_arguments() {
  owp_valid_domain "$JOIN_DOMAIN" || owp_die "invalid_arguments" "--join needs the domain of the panel"
  owp_private_ipv4 "$JOIN_VIA" || owp_die "invalid_arguments" "--via needs the private IPv4 address of the panel"
}

join_read_token() {
  local token=""
  if [[ -n "${OWP_JOIN_TOKEN_FILE:-}" && -f "$OWP_JOIN_TOKEN_FILE" ]]; then
    IFS= read -r token <"$OWP_JOIN_TOKEN_FILE" || true
    rm -f "$OWP_JOIN_TOKEN_FILE"
  elif [[ -r /dev/tty ]]; then
    read -r -s -p "Code from the panel (Server -> Add server): " token </dev/tty
    printf '\n' >/dev/tty
  fi
  [[ "$token" =~ ^[A-Za-z0-9_-]{32,128}$ ]] || owp_die "invalid_code" "this is not a code from the panel"
  JOIN_HEADERS="$(mktemp)"
  chmod 0600 "$JOIN_HEADERS"
  printf 'Authorization: Bearer %s\nAccept: text/plain\n' "$token" >"$JOIN_HEADERS"
}

# The panel tells its version, the name it chose, its time zone and its
# SSH key, one key=value per line. Everything is checked here.
join_info() {
  local file value
  file="$(mktemp)"
  join_curl --output "$file" "https://${JOIN_DOMAIN}/join/info" \
    || owp_die "panel_unreachable" "the panel did not answer over ${JOIN_VIA}, or the code is wrong or expired"
  value="$(sed -n 's/^version=//p' "$file" | head -n 1)"
  [[ "$value" == "$VERSION" ]] || owp_die "version_mismatch" "the panel runs ${value:-another version}, this installer is ${VERSION}"
  JOIN_NAME="$(sed -n 's/^name=//p' "$file" | head -n 1)"
  [[ "$JOIN_NAME" =~ ^[a-z0-9]([a-z0-9.-]{0,61}[a-z0-9])?$ ]] || owp_die "invalid_answer" "the panel sent no valid server name"
  TIMEZONE="$(sed -n 's/^timezone=//p' "$file" | head -n 1)"
  owp_valid_timezone "$TIMEZONE" || owp_die "invalid_answer" "the panel sent no valid time zone"
  JOIN_KEY_FILE="$(mktemp)"
  sed -n 's/^ssh_key=//p' "$file" | head -n 1 >"$JOIN_KEY_FILE"
  grep -qE '^ssh-ed25519 [A-Za-z0-9+/=]{60,100}( [A-Za-z0-9@._-]{1,64})?$' "$JOIN_KEY_FILE" \
    || owp_die "invalid_answer" "the panel sent no valid SSH key"
  rm -f "$file"
  ok "panel ${JOIN_DOMAIN} answers over the private network, this server becomes ${JOIN_NAME}"
}

# This server's addresses: the private one towards the panel and the
# private networks on that card, the public ones on the default route.
join_network() {
  local route dev net card
  route="$(ip -4 route get "$JOIN_VIA" 2>/dev/null | head -n 1)"
  JOIN_PRIVATE_IP="$(sed -n 's/.* src \([0-9.]*\).*/\1/p' <<<"$route")"
  dev="$(sed -n 's/.* dev \([^ ]*\).*/\1/p' <<<"$route")"
  owp_private_ipv4 "$JOIN_PRIVATE_IP" || owp_die "no_private_network" "this server has no private address towards ${JOIN_VIA}"
  JOIN_PRIVATE_NETS=""
  while read -r net; do
    owp_valid_private_net "$net" && JOIN_PRIVATE_NETS+="${JOIN_PRIVATE_NETS:+ }${net}"
  done < <(ip -4 route show dev "$dev" 2>/dev/null | awk '$1 ~ /\// { print $1 }')
  [[ -n "$JOIN_PRIVATE_NETS" ]] || JOIN_PRIVATE_NETS="${JOIN_VIA}/32"
  card="$(ip -4 route show default 2>/dev/null | sed -n 's/.* dev \([^ ]*\).*/\1/p' | head -n 1)"
  if [[ -n "$card" ]]; then
    JOIN_PUBLIC_IPV4="$(ip -4 -o addr show dev "$card" scope global 2>/dev/null | awk '{ print $4 }' | head -n 1)"
    JOIN_PUBLIC_IPV6="$(ip -6 -o addr show dev "$card" scope global 2>/dev/null | awk '{ print $4 }' | head -n 1)"
    JOIN_PUBLIC_IPV4="${JOIN_PUBLIC_IPV4%/*}"
    JOIN_PUBLIC_IPV6="${JOIN_PUBLIC_IPV6%/*}"
  fi
  ok "private address ${JOIN_PRIVATE_IP}, private networks ${JOIN_PRIVATE_NETS}"
}

# Host name of this server (E-150): asked in a terminal, the current one
# by default.
join_hostname() {
  local current answer
  current="$(hostname -f 2>/dev/null || hostname)"
  HOSTNAME_NEW="${HOSTNAME_NEW:-$current}"
  if [[ -r /dev/tty && -t 1 && -z "${OWP_JOIN_UNATTENDED:-}" ]]; then
    read -r -p "Host name of this server [${HOSTNAME_NEW}]: " answer </dev/tty || true
    [[ -z "$answer" ]] || HOSTNAME_NEW="${answer,,}"
  fi
  owp_valid_domain "$HOSTNAME_NEW" || owp_die "invalid_hostname" "${HOSTNAME_NEW} is not a host name like web2.example.de"
  if [[ "$HOSTNAME_NEW" != "$current" ]]; then
    owp_set_hostname "$HOSTNAME_NEW"
    ok "host name ${HOSTNAME_NEW} (was ${current})"
  fi
}

join_write_role() {
  install -d -o root -g root -m 0755 "$(dirname "$JOIN_ROLE_FILE")"
  printf '# Written by the OWPanel installer (E-196).\nROLE=web\nPRIVATE_NETS=%s\nPANEL=%s\n' \
    "$JOIN_PRIVATE_NETS" "$JOIN_DOMAIN" >"${JOIN_ROLE_FILE}.new"
  chmod 0644 "${JOIN_ROLE_FILE}.new"
  mv -f "${JOIN_ROLE_FILE}.new" "$JOIN_ROLE_FILE"
}

# Registers at the panel; it pins the host key and confirms the firewall.
join_register() {
  local answer
  answer="$(join_curl -X POST "https://${JOIN_DOMAIN}/join/register" \
    --data-urlencode "hostname=${HOSTNAME_NEW}" \
    --data-urlencode "private_ipv4=${JOIN_PRIVATE_IP}" \
    --data-urlencode "public_ipv4=${JOIN_PUBLIC_IPV4}" \
    --data-urlencode "public_ipv6=${JOIN_PUBLIC_IPV6}" \
    --data-urlencode "ssh_port=${SSH_PORT}" \
    --data-urlencode "host_key=$(cut -d' ' -f1-2 /etc/ssh/ssh_host_ed25519_key.pub)" \
    --data-urlencode "backend_cert@/etc/owpanel/backend/backend.crt")" \
    || owp_die "register_failed" "the panel did not take this server; the code may be used or expired"
  grep -qx 'ok=1' <<<"$answer" || owp_die "register_failed" "unexpected answer from the panel"
  ok "registered at the panel as ${JOIN_NAME}"
}

# The panel confirms the firewall over SSH within the rollback time.
join_wait_for_firewall() {
  local waited=0
  owp_log "waiting for the panel to confirm the firewall"
  while [[ -e "$JOIN_FW_PENDING" ]] && ((waited < 70)); do
    sleep 2
    waited=$((waited + 2))
  done
  # Read first: grep -q in a pipe ends early, nft then dies of SIGPIPE and
  # pipefail would call a confirmed firewall a failure.
  local rules
  rules="$(nft list table inet owpanel 2>/dev/null || true)"
  if [[ -e "$JOIN_FW_PENDING" || "$rules" != *'OWPanel private network'* ]]; then
    owp_die "firewall_not_confirmed" "the panel did not confirm the firewall; it was rolled back. Check SSH from the panel over ${JOIN_PRIVATE_IP}"
  fi
  ok "firewall confirmed by the panel"
}

join_main() {
  # shellcheck disable=SC2034  # read by step() in install
  STEPS=6
  trap join_cleanup EXIT
  join_check_arguments
  banner
  printf '\n%sThis server joins the panel %s as a website server.%s\n' "$C_DIM" "$JOIN_DOMAIN" "$C_RESET"

  step "Panel"
  join_read_token
  join_info
  join_network
  join_hostname

  owp_apt_pause_updates
  step "Agent user and server scripts"
  run_script setup-agent "${SOURCE_ROOT}/server/provision/setup-agent" --public-key-file "$JOIN_KEY_FILE"
  find_ssh_port
  join_write_role
  write_release_key
  write_unsigned_permission

  step "Time zone"
  run_script server-timezone /opt/owpanel/bin/server-timezone --zone "$TIMEZONE"

  step "Base services (this takes a few minutes)"
  owp_log "updating the system: package lists and all pending updates"
  owp_apt_upgrade
  run_script server-provision /opt/owpanel/bin/server-provision

  step "Connect to the panel"
  join_register
  join_wait_for_firewall
  owp_apt_resume_updates

  step "Done"
  printf '\n%s%s✓ %s is a website server of %s%s\n\n' "$C_GREEN" "$C_BOLD" "$HOSTNAME_NEW" "$JOIN_DOMAIN" "$C_RESET"
  printf '  %sWebsites on it are reached through the gateway of the panel; open to the public is only SSH.%s\n\n' "$C_DIM" "$C_RESET"
}
