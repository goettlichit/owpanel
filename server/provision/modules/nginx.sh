# shellcheck shell=bash
# shellcheck disable=SC2154  # TEMPLATES comes from server-provision

# NGINX comes from nginx.org (E-042). Its nginx.conf stays untouched and
# includes conf.d/*.conf, where OWPanel adds its own files.
# Key and UDP buffers for HTTP/3 (E-025). The key is created once; a new one
# on every run would break QUIC connections just like a reload without key.
provision_quic() {
  local key=/etc/owpanel/quic_host.key
  install -d -o root -g root -m 0755 /etc/owpanel
  if [[ ! -s "$key" ]]; then
    (umask 077 && head -c 32 /dev/urandom >"$key")
  fi
  chown root:root "$key"
  chmod 0600 "$key"

  install -o root -g root -m 0644 "${TEMPLATES}/sysctl/60-owpanel-quic.conf" \
    /etc/sysctl.d/60-owpanel-quic.conf
  sysctl --quiet --load=/etc/sysctl.d/60-owpanel-quic.conf \
    || owp_die "sysctl_failed" "could not set the UDP buffer sizes"
}

# Own certificate of the backend (E-186, E-195): the gateway pins it, so it
# never needs a CA. The key stays on this server; the panel learns the
# certificate from server-status.
provision_backend() {
  local dir=/etc/owpanel/backend
  # Binding before the private address exists, e.g. at boot (E-196).
  install -o root -g root -m 0644 "${TEMPLATES}/sysctl/60-owpanel-backend.conf" \
    /etc/sysctl.d/60-owpanel-backend.conf
  sysctl --quiet --load=/etc/sysctl.d/60-owpanel-backend.conf \
    || owp_die "sysctl_failed" "could not allow binding the private address"
  install -d -o root -g root -m 0755 /etc/owpanel "$dir" /etc/owpanel/backends \
    /etc/nginx/owpanel-backend /etc/nginx/owpanel-upstreams
  if [[ ! -s "${dir}/backend.key" || ! -s "${dir}/backend.crt" ]]; then
    (umask 077 && openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes \
      -days 3650 -subj "/CN=owpanel-backend" -addext "subjectAltName=DNS:owpanel-backend" \
      -keyout "${dir}/backend.key" -out "${dir}/backend.crt" 2>/dev/null) \
      || owp_die "backend_certificate_failed" "could not create the backend certificate"
    owp_log "backend certificate created"
  fi
  chown root:root "${dir}/backend.key" "${dir}/backend.crt"
  chmod 0600 "${dir}/backend.key"
  chmod 0644 "${dir}/backend.crt"
}

provision_nginx() {
  owp_apt_install nginx

  if ! nginx -V 2>&1 | grep -q -- '--with-http_v3_module'; then
    owp_die "nginx_http3_missing" "nginx was built without http_v3_module (E-025)"
  fi

  install -d -o root -g root -m 0755 /etc/nginx/owpanel /etc/nginx/owpanel-sites
  local file
  provision_backend
  for file in fastcgi-php headers gateway-headers gateway-proxy backend-tls backend-headers \
    fastcgi-backend-params fastcgi-backend; do
    owp_apply_config "${TEMPLATES}/nginx/${file}.conf" \
      "/etc/nginx/owpanel/${file}.conf" 0644 nginx reload -- nginx -t \
      || owp_die "nginx_config_invalid" "nginx rejected ${file}.conf"
  done

  provision_quic

  for file in owpanel-hardening owpanel-compression owpanel-quic owpanel-backend owpanel-gateway \
    owpanel-sites owpanel-default; do
    owp_apply_config "${TEMPLATES}/nginx/${file}.conf" \
      "/etc/nginx/conf.d/${file}.conf" 0644 nginx reload -- nginx -t \
      || owp_die "nginx_config_invalid" "nginx rejected ${file}.conf"
  done

  # The welcome site would answer for "localhost". Replacing the file keeps it
  # managed by dpkg, so package updates do not bring it back.
  owp_apply_config "${TEMPLATES}/nginx/default-placeholder.conf" \
    /etc/nginx/conf.d/default.conf 0644 nginx reload -- nginx -t \
    || owp_die "nginx_config_invalid" "nginx rejected the replaced default.conf"

  systemctl enable --now nginx
  nginx -t || owp_die "nginx_config_invalid" "nginx configuration is invalid"
  systemctl reload-or-restart nginx
}
