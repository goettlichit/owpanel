# shellcheck shell=bash
# shellcheck disable=SC2154  # TEMPLATES comes from server-provision

# Certificates are requested per site by site-create with the webroot method,
# so certbot never edits nginx configurations itself.
provision_certbot() {
  local hooks="/etc/letsencrypt/renewal-hooks/deploy"

  owp_apt_install certbot
  install -d -o root -g root -m 0755 /var/lib/owpanel /var/lib/owpanel/acme
  install -d -o root -g root -m 0755 /etc/letsencrypt /etc/letsencrypt/renewal-hooks "$hooks"
  install -o root -g root -m 0755 "${TEMPLATES}/certbot/owpanel-reload-nginx" \
    "${hooks}/owpanel-reload-nginx"
  systemctl enable --now certbot.timer
}
