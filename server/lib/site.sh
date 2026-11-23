# shellcheck shell=bash
# Helpers for website scripts. Sourced after common.sh, never executed.

# Lowercase host name with at least two labels. Rejects IP addresses, trailing
# dots, uppercase letters and everything outside [a-z0-9.-].
owp_valid_domain() {
  local domain="$1" label tld
  local label_re='^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$'
  local tld_re='^([a-z]{2,63}|xn--[a-z0-9-]{1,59})$'
  local -a labels=()

  [[ "${#domain}" -ge 4 && "${#domain}" -le 253 ]] || return 1
  [[ "$domain" =~ ^[a-z0-9.-]+$ ]] || return 1
  [[ "$domain" != .* && "$domain" != *. && "$domain" != *..* ]] || return 1

  IFS=. read -r -a labels <<<"$domain"
  [[ "${#labels[@]}" -ge 2 ]] || return 1
  for label in "${labels[@]}"; do
    [[ "$label" =~ $label_re ]] || return 1
  done
  tld="${labels[-1]}"
  [[ "$tld" =~ $tld_re ]] || return 1
}

# Site users are ow followed by a random number from 10000 to 59999, chosen
# and kept unique by the panel. The number is also UID and GID, so ow38217
# has UID 38217 (E-034).
owp_valid_site_user() {
  [[ "$1" =~ ^ow([1-5][0-9]{4})$ ]] && ((BASH_REMATCH[1] >= 10000))
}

# ow10000 is the panel itself on the first server (E-041). Site scripts never
# create or delete it; the installer sets it up.
owp_reserved_site_user() {
  [[ "$1" == "ow10000" ]]
}

# Usage: owp_site_uid <valid site user>
owp_site_uid() {
  printf '%s\n' "${1#ow}"
}

# Replaces @KEY@ placeholders. Values must be validated before. The quoted
# replacement keeps "&" literal in bash 5.2 and later.
# Usage: owp_render <template> <output> KEY=VALUE...
owp_render() {
  local template="$1" output="$2" content pair key value
  shift 2
  content="$(<"$template")"
  for pair in "$@"; do
    key="${pair%%=*}"
    value="${pair#*=}"
    [[ "$key" =~ ^[A-Z_]+$ ]] || return 1
    content="${content//"@${key}@"/"$value"}"
  done
  if [[ "$content" =~ @[A-Z_]+@ ]]; then
    printf 'error: unresolved placeholder %s in %s\n' "${BASH_REMATCH[0]}" "$template" >&2
    return 1
  fi
  printf '%s\n' "$content" >"$output"
}

# Members of this group get SFTP into their website directory (E-089).
# shellcheck disable=SC2034  # used by site-sftp and the sftp module
readonly OWP_SFTP_GROUP="owpanel-sftp"

# All host names a nginx site file answers to, one per line (E-110).
# Usage: owp_server_names <file>
owp_server_names() {
  sed -nE 's/^[[:space:]]*server_name[[:space:]]+([^;]*);.*$/\1/p' "$1" | tr -s ' \t' '\n' | sed '/^$/d'
}

# Writes the nginx configuration of a site: the main block, over HTTPS if
# the certificate covers the domain, and for --www a block for www.<domain>
# that sends everything to the main address; over HTTPS as well once the
# certificate covers that name too (E-110).
# Usage: owp_site_nginx <templates> <output> <http|https> <domain> <user> <root> <www 0|1> <www_tls 0|1> [<body MB> <timeout s>]
owp_site_nginx() {
  local templates="$1" output="$2" variant="$3" domain="$4" user="$5" root="$6" www="$7" www_tls="$8"
  local body="${9:-72}" timeout="${10:-90}" part="${output}.part" scheme=http
  [[ "$variant" == https ]] && scheme=https
  owp_render "${templates}/site-php-${variant}.conf" "$output" \
    "DOMAIN=${domain}" "USER=${user}" "ROOT=${root}" "BODY=${body}" "TIMEOUT=${timeout}" || return 1
  [[ "$www" -eq 1 ]] || return 0
  owp_render "${templates}/site-www-http.conf" "$part" "DOMAIN=${domain}" "SCHEME=${scheme}" || return 1
  { printf '\n'; cat "$part"; } >>"$output"
  if [[ "$variant" == https && "$www_tls" -eq 1 ]]; then
    owp_render "${templates}/site-www-https.conf" "$part" "DOMAIN=${domain}" "USER=${user}" || return 1
    { printf '\n'; cat "$part"; } >>"$output"
  fi
  rm -f "$part"
}
