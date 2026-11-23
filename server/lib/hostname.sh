# shellcheck shell=bash
# Host name of the server (E-150). Only the installer changes it, on a
# first run; the panel and its scripts never do.

OWP_HOSTS_FILE="${OWP_HOSTS_FILE:-/etc/hosts}"
OWP_CLOUD_CFG_DIR="${OWP_CLOUD_CFG_DIR:-/etc/cloud/cloud.cfg.d}"
readonly OWP_HOSTNAME_CLOUD_CFG="99-owpanel-hostname.cfg"

# A short name like srv03 or a full one like srv03.example.de: lower case
# letters, digits and hyphens, at most 63 characters per label, 253 in all.
# The first label must not be digits only, so it is never taken for an
# address.
owp_valid_hostname() {
  local name="$1" label
  local -a labels
  [[ "$name" =~ ^[a-z0-9.-]+$ && ${#name} -le 253 && "$name" != *. && "$name" != .* && "$name" != *..* ]] || return 1
  IFS=. read -r -a labels <<<"$name"
  for label in "${labels[@]}"; do
    [[ "$label" =~ ^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$ ]] || return 1
  done
  [[ ! "${labels[0]}" =~ ^[0-9]+$ ]]
}

# Prints the hosts file with "127.0.1.1 <full> <short>" for the new name,
# as Debian writes it: an existing 127.0.1.1 line is replaced, otherwise
# the line follows the one for 127.0.0.1, or ends the file. Everything else
# stays as it is.
owp_hosts_with_name() {
  local file="$1" full="$2" short="${2%%.*}" names
  names="$full"
  [[ "$full" == "$short" ]] || names="${full} ${short}"
  # Two passes over the file: the first only looks for a 127.0.1.1 line.
  awk -v line="127.0.1.1	${names}" '
    FNR == NR { if ($1 == "127.0.1.1") present = 1; next }
    $1 == "127.0.1.1" { if (!added) print line; added = 1; next }
    { print }
    !present && !added && $1 == "127.0.0.1" { print line; added = 1 }
    END { if (!added) print line }
  ' "$file" "$file"
}

# Sets the host name: /etc/hostname gets the short name, /etc/hosts the
# full and the short one. cloud-init (Hetzner) would set the old name again
# at the next boot, so it is told to keep it. The old hosts file comes back
# when anything fails. Repeatable.
owp_set_hostname() {
  local full="$1" short="${1%%.*}" backup next
  owp_valid_hostname "$full" || owp_die "invalid_hostname" "invalid host name: ${full}"

  if [[ -d "$OWP_CLOUD_CFG_DIR" ]]; then
    printf '%s\n' "# Written by the OWPanel installer (E-150): keep the host name that was" \
      "# chosen during the installation, also in /etc/hosts." \
      "preserve_hostname: true" "manage_etc_hosts: false" >"${OWP_CLOUD_CFG_DIR}/${OWP_HOSTNAME_CLOUD_CFG}"
    chmod 0644 "${OWP_CLOUD_CFG_DIR}/${OWP_HOSTNAME_CLOUD_CFG}"
  fi

  backup="$(mktemp "${OWP_HOSTS_FILE}.owpanel.XXXXXX")"
  next="$(mktemp "${OWP_HOSTS_FILE}.owpanel-next.XXXXXX")"
  cp -p "$OWP_HOSTS_FILE" "$backup"
  owp_hosts_with_name "$OWP_HOSTS_FILE" "$full" >"$next"
  install -m 0644 "$next" "$OWP_HOSTS_FILE"
  rm -f "$next"

  if ! hostnamectl set-hostname "$short"; then
    install -m 0644 "$backup" "$OWP_HOSTS_FILE"
    rm -f "$backup"
    owp_die "hostname_failed" "could not set the host name; /etc/hosts is back as before"
  fi
  rm -f "$backup"
  owp_log "host name: ${full}"
}
