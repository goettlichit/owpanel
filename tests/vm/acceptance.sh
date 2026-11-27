#!/usr/bin/env bash
# Acceptance test for milestone 1 on a disposable Debian 13 VM. Runs every
# server script twice and checks the result. Never run it on a production
# server: it changes the firewall, services and websites.
#
#   touch /etc/owpanel-test-vm       # once, marks the VM as disposable
#   tests/vm/acceptance.sh --domain test.example.de --public-key-file /root/panel.pub
#
#   --user ow<number>  site user to create, default ow10001
#
# Options:
#   --no-tls        skip Let's Encrypt, e.g. to spare the rate limit
#   --timezone Z    time zone to set, default Europe/Berlin
#   --delete-cycle  also delete the site and create it again (one more
#                   certificate; Let's Encrypt allows 5 per domain in 7 days)
#
# The full output is also written to /root/owpanel-acceptance-<time>.log.
set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
readonly REPO
readonly MARKER="/etc/owpanel-test-vm"
readonly BIN="/opt/owpanel/bin"

PASSED=0
FAILED=0
FAILURES=()
STEP=0
LAST_OK=1
LAST_LINE=""
START=$SECONDS

usage() {
  echo "usage: $0 --domain <domain> --public-key-file <path> [--user owNNNN] [--timezone Area/City] [--no-tls] [--delete-cycle]" >&2
  exit 2
}

# Prints the time since the start, e.g. 03:12
elapsed() {
  local s=$((SECONDS - START))
  printf '%02d:%02d' $((s / 60)) $((s % 60))
}

pass() {
  PASSED=$((PASSED + 1))
  printf 'PASS  %s\n' "$1"
}

fail() {
  FAILED=$((FAILED + 1))
  FAILURES+=("$1")
  printf 'FAIL  %s\n' "$1"
}

# Usage: check <description> <command...>
# The description is printed first, so a slow check never looks like a hang.
check() {
  local description="$1"
  shift
  printf '[%s] check: %s ... ' "$(elapsed)" "$description"
  if "$@" >/dev/null 2>&1; then
    printf 'ok\n'
    PASSED=$((PASSED + 1))
  else
    printf 'FAILED\n'
    FAILED=$((FAILED + 1))
    FAILURES+=("$description")
  fi
}

# Runs a server script with live output and checks the JSON result line.
# Usage: run_script <description> <expected JSON fragment> <command...>
run_script() {
  local description="$1" expected="$2" out last started=$SECONDS
  shift 2
  STEP=$((STEP + 1))
  out="$(mktemp)"
  printf '\n===== [%s] step %d: %s\n' "$(elapsed)" "$STEP" "$description"
  # Each output line appears at once, indented to set it apart.
  "$@" 2>&1 | tee "$out" | sed -u 's/^/    /' || true
  last="$(tail -n 1 "$out")"
  LAST_LINE="$last"
  rm -f "$out"
  printf '      step %d took %ds: ' "$STEP" $((SECONDS - started))
  if [[ "$last" == *'"ok":true'* && "$last" == *"$expected"* ]]; then
    LAST_OK=1
    pass "$description"
  else
    LAST_OK=0
    fail "$description"
  fi
}

# Stops the test when the last step failed and later steps depend on it.
abort_on_failure() {
  [[ "$LAST_OK" -eq 1 ]] && return 0
  echo
  echo "##### Aborted after $(elapsed): $1"
  echo "##### ${PASSED} passed, ${FAILED} failed"
  for f in "${FAILURES[@]}"; do echo "  failed: $f"; done
  exit 1
}

udp_443_listening() {
  [[ -n "$(ss -ulnH 'sport = :443')" ]]
}

quic_key_protected() {
  [[ "$(stat -c %U:%a /etc/owpanel/quic_host.key)" == root:600 ]]
}

udp_buffers_raised() {
  (($(sysctl -n net.core.rmem_max) >= 7500000 && $(sysctl -n net.core.wmem_max) >= 7500000))
}

# True if the script ran but reported an error.
agent_denied_result() {
  local out
  out="$(cd / && sudo -u owpanel-agent sudo -n "$@" 2>/dev/null | tail -n 1)" || true
  [[ "$out" == *'"ok":false'* ]]
}

auto_updates_on() {
  [[ "$(apt-config shell X APT::Periodic::Unattended-Upgrade)" == "X='1'" ]]
}

as_agent() {
  (cd / && sudo -u owpanel-agent sudo -n "$@")
}

agent_denied() {
  ! (cd / && sudo -u owpanel-agent sudo -n "$@")
}

main() {
  local domain="" key_file="" user="ow10001" timezone="Europe/Berlin" tls=1 delete_cycle=0 log php uid site_flags=() gateway=()

  while [[ $# -gt 0 ]]; do
    case "$1" in
      --domain) [[ $# -ge 2 ]] || usage; domain="$2"; shift 2 ;;
      --public-key-file) [[ $# -ge 2 ]] || usage; key_file="$2"; shift 2 ;;
      --user) [[ $# -ge 2 ]] || usage; user="$2"; shift 2 ;;
      --timezone) [[ $# -ge 2 ]] || usage; timezone="$2"; shift 2 ;;
      --no-tls) tls=0; shift ;;
      --delete-cycle) delete_cycle=1; shift ;;
      *) usage ;;
    esac
  done
  [[ -n "$domain" && -n "$key_file" ]] || usage
  # shellcheck source=server/lib/site.sh
  source "${REPO}/server/lib/site.sh"
  owp_valid_domain "$domain" || { echo "invalid domain" >&2; exit 2; }
  owp_valid_site_user "$user" || { echo "invalid user, expected ow10000 to ow59999" >&2; exit 2; }
  uid="$(owp_site_uid "$user")"
  [[ "$(id -u)" -eq 0 ]] || { echo "run as root" >&2; exit 2; }
  if [[ ! -e "$MARKER" ]]; then
    echo "refusing to run: ${MARKER} is missing. Create it only on a disposable test VM." >&2
    exit 2
  fi

  log="/root/owpanel-acceptance-$(date +%Y%m%d-%H%M%S).log"
  exec > >(tee "$log") 2>&1
  echo "OWPanel acceptance test, $(date -Is), scripts from ${REPO}"
  [[ "$tls" -eq 1 ]] && site_flags=(--tls)
  # The gateway passes the website to the backend on this server (E-195).
  gateway=(--domain "$domain" --user "$user" --backend-id acceptance --backend 127.0.0.1)

  echo "##### Agent and base services"
  run_script "setup-agent, run 1" "" "${REPO}/server/provision/setup-agent" --public-key-file "$key_file"
  run_script "setup-agent, run 2" "" "${REPO}/server/provision/setup-agent" --public-key-file "$key_file"
  echo "server-provision installs packages on the first run, this takes a few minutes."
  # On a fresh VM the firewall is new and needs a confirmation. On a VM that
  # is already set up it is unchanged, which is just as correct.
  run_script "server-provision, run 1" "" as_agent "${BIN}/server-provision"
  abort_on_failure "server-provision did not finish, the following checks would only fail"
  if [[ "$LAST_LINE" == *'"firewall_confirm_required":true'* ]]; then
    check "firewall rollback timer is armed" systemctl is-active --quiet owpanel-firewall-rollback.timer
    # In production the panel confirms over a new SSH connection.
    run_script "firewall-confirm" '"confirmed":true' as_agent "${BIN}/firewall-confirm"
    check "firewall rollback timer is stopped" sh -c '! systemctl is-active --quiet owpanel-firewall-rollback.timer'
  else
    printf '[%s] firewall unchanged, it was confirmed on an earlier run\n' "$(elapsed)"
  fi
  run_script "server-provision, run 2" '"firewall_confirm_required":false' as_agent "${BIN}/server-provision"

  php="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
  run_script "server-timezone, run 1" "\"timezone\":\"${timezone}\"" as_agent "${BIN}/server-timezone" --zone "$timezone"
  run_script "server-timezone, run 2" "\"timezone\":\"${timezone}\"" as_agent "${BIN}/server-timezone" --zone "$timezone"
  check "server runs in time zone ${timezone}" sh -c "[ \"\$(timedatectl show --property=Timezone --value)\" = '${timezone}' ]"
  check "clock is synchronized by NTP" sh -c "[ \"\$(timedatectl show --property=NTP --value)\" = yes ]"
  check "server-timezone rejects unknown zones" agent_denied_result "${BIN}/server-timezone" --zone "Europe/Nowhere"
  run_script "panel-test runs as root through sudo" '"user":"root"' as_agent "${BIN}/panel-test" --steps 1
  check "panel-test reports a requested failure" agent_denied_result "${BIN}/panel-test" --steps 1 --fail

  echo
  echo "##### Services and network"
  check "services are active" systemctl is-active --quiet nginx mariadb fail2ban certbot.timer "php${php}-fpm"
  check "firewall table is loaded" nft list table inet owpanel
  check "firewall drops by default" sh -c 'nft list chain inet owpanel input | grep -q "policy drop"'
  check "firewall allows UDP 443 for HTTP/3" sh -c 'nft list table inet owpanel | grep -q "udp dport 443 accept"'
  check "firewall rules are saved for reboots" test -s /etc/owpanel/firewall.nft
  check "firewall loads at boot" systemctl is-enabled --quiet owpanel-firewall.service
  check "fail2ban bans through nftables" grep -qx 'banaction = nftables-multiport' /etc/fail2ban/jail.d/owpanel.local
  check "MariaDB listens on localhost only" sh -c '! ss -tlnH "sport = :3306" | grep -qv "127.0.0.1:3306"'
  check "fail2ban watches sshd" fail2ban-client status sshd
  check "nginx comes from nginx.org" sh -c "apt-cache policy nginx | grep -A1 '^ \*\*\*' | grep -q 'nginx.org'"
  check "nginx has the HTTP/3 module" sh -c "nginx -V 2>&1 | grep -q -- --with-http_v3_module"
  check "nginx listens for QUIC on UDP 443" udp_443_listening
  check "QUIC key belongs to root with mode 600" quic_key_protected
  check "UDP buffers are raised for QUIC" udp_buffers_raised
  check "PHP 8.5 is installed" test "$php" = 8.5
  check "automatic updates are on" auto_updates_on
  check "automatic updates cover nginx.org and sury.org" \
    sh -c "apt-config dump | grep -q 'site=nginx.org' && apt-config dump | grep -q 'site=packages.sury.org'"
  check "apt timers are active" systemctl is-active --quiet apt-daily.timer apt-daily-upgrade.timer
  check "unattended-upgrades dry run works" sh -c "unattended-upgrade --dry-run >/dev/null 2>&1"
  check "default PHP pool runs as nobody" grep -qx 'user = nobody' "/etc/php/${php}/fpm/pool.d/www.conf"

  echo
  echo "##### Agent restrictions"
  check "agent cannot start a shell" agent_denied /bin/bash -c true
  check "agent cannot run apt-get" agent_denied /usr/bin/apt-get --version
  check "agent cannot write scripts" sh -c '! sudo -u owpanel-agent touch /opt/owpanel/bin/x 2>/dev/null'
  check "agent cannot change its SSH key" sh -c '! sudo -u owpanel-agent touch /var/lib/owpanel-agent/.ssh/authorized_keys 2>/dev/null'
  check "sudo rules pass visudo" visudo -cqf /etc/sudoers.d/owpanel-agent

  echo
  echo "##### Website ${domain} as ${user}"
  # Backend first, then the gateway with the certificate (E-195). Without
  # --tls a certificate from an earlier run still counts, because the
  # gateway keeps a site on HTTPS while its certificate covers the domain.
  local expected="" site_tls want_scheme
  local -a https_flag=()
  [[ "$tls" -eq 1 ]] && expected='"tls":true'
  run_script "site-create, run 1" "" as_agent "${BIN}/site-create" --domain "$domain" --type php --user "$user"
  abort_on_failure "site-create did not finish"
  run_script "gateway-site, run 1" "$expected" as_agent "${BIN}/gateway-site" "${gateway[@]}" "${site_flags[@]}"
  abort_on_failure "gateway-site did not finish"
  site_tls=0
  [[ "$LAST_LINE" == *'"tls":true'* ]] && site_tls=1
  [[ "$site_tls" -eq 1 && "$tls" -eq 0 ]] && printf '[%s] the site keeps its certificate from an earlier run\n' "$(elapsed)"
  expected='"tls":false'
  [[ "$site_tls" -eq 1 ]] && expected='"tls":true'
  https_flag=()
  want_scheme=http
  if [[ "$site_tls" -eq 1 ]]; then
    https_flag=(--https)
    want_scheme=https
  fi
  run_script "site-create, run 2" "" as_agent "${BIN}/site-create" --domain "$domain" --type php --user "$user" "${https_flag[@]}"
  run_script "gateway-site, run 2" "$expected" as_agent "${BIN}/gateway-site" "${gateway[@]}" "${site_flags[@]}"
  check "backend listens on 127.0.0.1:8443 only" sh -c "ss -tln | grep -q '127.0.0.1:8443' && ! ss -tln | grep -qE '(0\\.0\\.0\\.0|\\*|\\[::\\]):8443'"
  check "the gateway pins the backend certificate" cmp -s /etc/owpanel/backend/backend.crt /etc/owpanel/backends/acceptance.crt
  # shellcheck disable=SC2016  # PHP code, not shell
  runuser -u "$user" -- tee "/var/www/${user}/htdocs/public/scheme.php" >/dev/null <<<'<?php echo $_SERVER["REQUEST_SCHEME"], " ", $_SERVER["REMOTE_ADDR"];'
  check "PHP sees the visitor's scheme and address" \
    sh -c "[ \"\$(curl -sk -L --resolve '${domain}:80:127.0.0.1' --resolve '${domain}:443:127.0.0.1' 'http://${domain}/scheme.php')\" = '${want_scheme} 127.0.0.1' ]"
  rm -f "/var/www/${user}/htdocs/public/scheme.php"
  check "site user has UID and GID ${uid}" sh -c "[ \"\$(id -u '$user'):\$(id -g '$user')\" = '${uid}:${uid}' ]"
  check "/etc/passwd does not contain the domain" sh -c "! grep -qF '${domain}' /etc/passwd /etc/group"
  check "site scripts refuse the panel user ow10000" \
    sh -c "! (cd / && sudo -u owpanel-agent sudo -n '${BIN}/site-delete' --user ow10000) >/dev/null 2>&1"
  check "a second site cannot take the same domain" \
    sh -c "! (cd / && sudo -u owpanel-agent sudo -n '${BIN}/site-create' --domain '$domain' --type php --user ow59999) >/dev/null 2>&1 && ! getent passwd ow59999"
  check_site "$domain" "$user" "$site_tls" "$php"

  echo
  echo "##### Suspend and unsuspend (E-127)"
  local scheme=http port=80 before
  [[ "$site_tls" -eq 1 ]] && scheme=https port=443
  printf '* * * * *\tdate\n' | as_agent "${BIN}/site-cron" --user "$user" >/dev/null 2>&1 || true
  before="$(sha256sum "/etc/nginx/owpanel-sites/${user}.conf")"
  # The gateway shows the page, the server rests the rest (E-195).
  run_script "gateway-site --suspended" '"suspended":true' as_agent "${BIN}/gateway-site" "${gateway[@]}" --suspended
  run_script "site-suspend, run 1" '"suspended":true' as_agent "${BIN}/site-suspend" --user "$user"
  run_script "site-suspend, run 2" '"suspended":true' as_agent "${BIN}/site-suspend" --user "$user"
  sleep 2
  check "suspended site answers 503" \
    sh -c "[ \"\$(curl -sk -o /dev/null -w '%{http_code}' --resolve '${domain}:${port}:127.0.0.1' '${scheme}://${domain}/')\" = 503 ]"
  check "suspended site shows the neutral page" \
    sh -c "curl -sk --resolve '${domain}:${port}:127.0.0.1' '${scheme}://${domain}/' | grep -q 'vorübergehend nicht erreichbar'"
  check "ACME path stays reachable" \
    sh -c "[ \"\$(curl -s -o /dev/null -w '%{http_code}' --resolve '${domain}:80:127.0.0.1' 'http://${domain}/.well-known/acme-challenge/x')\" = 404 ]"
  check "PHP-FPM pool rests" test ! -e "/etc/php/${php}/fpm/pool.d/owpanel-${user}.conf"
  check "cron file is set aside" test ! -e "/etc/cron.d/owpanel-${user}"
  check "account is expired for SFTP" sh -c "[ \"\$(getent shadow '$user' | cut -d: -f8)\" = 1 ]"
  check "runuser still works for panel scripts" runuser -u "$user" -- true
  run_script "site-unsuspend, run 1" '"suspended":false' as_agent "${BIN}/site-unsuspend" --user "$user"
  run_script "site-unsuspend, run 2" '"suspended":false' as_agent "${BIN}/site-unsuspend" --user "$user"
  run_script "site-create after unsuspend" "" as_agent "${BIN}/site-create" --domain "$domain" --type php --user "$user" --existing "${https_flag[@]}"
  run_script "gateway-site after unsuspend" '"suspended":false' as_agent "${BIN}/gateway-site" "${gateway[@]}"
  check "nginx config is exactly as before" sh -c "[ \"\$(sha256sum '/etc/nginx/owpanel-sites/${user}.conf')\" = '${before}' ]"
  check "PHP-FPM pool is back" test -f "/etc/php/${php}/fpm/pool.d/owpanel-${user}.conf"
  check "account no longer expires" sh -c "[ -z \"\$(getent shadow '$user' | cut -d: -f8)\" ]"
  check "nothing is left aside" test ! -e "/var/lib/owpanel/suspended/${user}"
  wait_for_site "$domain" "$scheme" "$port"
  check_site "$domain" "$user" "$site_tls" "$php"
  as_agent "${BIN}/site-cron" --user "$user" </dev/null >/dev/null 2>&1 || true

  if [[ "$delete_cycle" -eq 1 ]]; then
    echo
    echo "##### Delete and create again"
    run_script "gateway-site --remove" '"removed":true' as_agent "${BIN}/gateway-site" --user "$user" --remove
    run_script "site-delete, run 1" "" as_agent "${BIN}/site-delete" --user "$user"
    run_script "site-delete, run 2" "" as_agent "${BIN}/site-delete" --user "$user"
    check "site user is gone" sh -c "! getent passwd '$user'"
    check "nginx left the site group" sh -c "! id -nG nginx | tr ' ' '\n' | grep -qx '$user'"
    check "site files are gone" test ! -e "/var/www/${user}"
    check "nginx config is gone" test ! -e "/etc/nginx/owpanel-sites/${user}.conf"
    check "backend config is gone" test ! -e "/etc/nginx/owpanel-backend/${user}.conf"
    check "certificate is gone" test ! -e "/etc/letsencrypt/renewal/${user}.conf"
    # Only --tls requests a new certificate here; without it the site comes
    # back on HTTP, because the removal took the old certificate.
    expected='"tls":false'
    [[ "$tls" -eq 1 ]] && expected='"tls":true'
    https_flag=()
    [[ "$tls" -eq 1 ]] && https_flag=(--https)
    run_script "site-create after delete" "" as_agent "${BIN}/site-create" --domain "$domain" --type php --user "$user" "${https_flag[@]}"
    run_script "gateway-site after delete" "$expected" as_agent "${BIN}/gateway-site" "${gateway[@]}" "${site_flags[@]}"
    check_site "$domain" "$user" "$tls" "$php"
  fi

  echo
  echo "##### Firewall rollback (waits about 70 seconds)"
  # Without the table the server is open, as before the first provisioning.
  nft delete table inet owpanel
  run_script "server-provision without confirmation" '"firewall_confirm_required":true' as_agent "${BIN}/server-provision"
  check "new rules are loaded" nft list table inet owpanel
  printf '[%s] waiting for the rollback timer ...\n' "$(elapsed)"
  sleep 70
  check "unconfirmed rules were rolled back" sh -c '! nft list table inet owpanel'
  run_script "server-provision after rollback" '"firewall_confirm_required":true' as_agent "${BIN}/server-provision"
  run_script "firewall-confirm after rollback" '"confirmed":true' as_agent "${BIN}/firewall-confirm"
  check "firewall table is loaded again" nft list table inet owpanel

  echo
  echo "##### Result after $(elapsed): ${PASSED} passed, ${FAILED} failed"
  for f in "${FAILURES[@]}"; do echo "  failed: $f"; done
  echo "Log: ${log}"
  [[ "$FAILED" -eq 0 ]]
}

# nginx reloads in the background, so a request right after site-create can
# still reach the old configuration. Waits up to 10 seconds for an answer
# from the site itself; the default server just closes the connection.
# Usage: wait_for_site <domain> <scheme> <port>
wait_for_site() {
  local code
  for _ in 1 2 3 4 5 6 7 8 9 10; do
    code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 --resolve "${1}:${3}:127.0.0.1" "${2}://${1}/" || true)"
    [[ "$code" != "000" ]] && return 0
    sleep 1
  done
  printf '[%s] the site did not answer within 10 seconds\n' "$(elapsed)"
}

# Usage: check_site <domain> <user> <tls 0|1> <php version>
check_site() {
  local domain="$1" user="$2" tls="$3" php="$4" scheme port
  runuser -u "$user" -- sh -c "echo '<?php echo PHP_VERSION;' > '/var/www/${user}/htdocs/public/index.php'"
  scheme=http port=80
  [[ "$tls" -eq 1 ]] && scheme=https port=443
  wait_for_site "$domain" "$scheme" "$port"
  if [[ "$tls" -eq 1 ]]; then
    check "HTTP redirects to HTTPS" \
      sh -c "[ \"\$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' --resolve '${domain}:80:127.0.0.1' 'http://${domain}/x')\" = '301 https://${domain}/x' ]"
    check "certificate renewal works (dry run)" certbot renew --dry-run --cert-name "$user"
    check "HTTPS advertises HTTP/3 with Alt-Svc" \
      sh -c "curl -sI --max-time 15 --resolve '${domain}:443:127.0.0.1' 'https://${domain}/' | grep -qi '^alt-svc: h3=\":443\"'"
    check "HTTPS sends the security headers" \
      sh -c "curl -sI --max-time 15 --resolve '${domain}:443:127.0.0.1' 'https://${domain}/' | grep -qi '^x-content-type-options: nosniff'"
    if curl --version | grep -q HTTP3; then
      check "site answers over HTTP/3" \
        sh -c "curl -s --http3-only --max-time 15 --resolve '${domain}:443:127.0.0.1' 'https://${domain}/' | grep -q '^${php}\.'"
      check "unknown domains are refused over HTTP/3" \
        sh -c "! curl -sk --http3-only --max-time 15 --resolve 'unknown.invalid:443:127.0.0.1' https://unknown.invalid/ >/dev/null"
    else
      printf '[%s] skip: curl on this server has no HTTP/3, check it from outside\n' "$(elapsed)"
    fi
  fi
  check "PHP ${php} answers" \
    sh -c "curl -s --max-time 15 --resolve '${domain}:${port}:127.0.0.1' '${scheme}://${domain}/' | grep -q '^${php}\.'"
  check "dot files are blocked" \
    sh -c "[ \"\$(curl -s -o /dev/null -w '%{http_code}' --resolve '${domain}:${port}:127.0.0.1' '${scheme}://${domain}/.env')\" = 403 ]"
  check "site directory is closed to other users" \
    sh -c "! sudo -u owpanel-agent ls '/var/www/${user}' 2>/dev/null"
}

main "$@"
