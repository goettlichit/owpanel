# shellcheck shell=bash
# Firewall helpers (E-044). Sourced after common.sh, never executed.
#
# A change is loaded at once but only saved for reboots after confirmation:
#   owp_firewall_apply   checks the rules, snapshots the live table, loads the
#                        new rules and arms a rollback timer
#   firewall-confirm     (bin) saves the rules and stops the timer
#   firewall-rollback    (provision) restores the snapshot when the timer fires

readonly OWP_FW_TABLE="inet owpanel"
readonly OWP_FW_SAVED="/etc/owpanel/firewall.nft"
readonly OWP_FW_STATE="/run/owpanel/firewall"
readonly OWP_FW_TIMER="owpanel-firewall-rollback"
readonly OWP_FW_TIMEOUT=60
# Own rules from the panel (E-090), one per line: protocol, port or range,
# source ("any" or an address with prefix), separated by tabs. Saved only
# after confirmation, like the rules themselves.
# shellcheck disable=SC2034  # used by firewall-confirm and the firewall module
readonly OWP_FW_RULES="/etc/owpanel/firewall-rules.list"
readonly OWP_FW_MAX_RULES=100

# Serializes apply, confirm and rollback, which all touch the same state.
owp_firewall_lock() {
  install -d -o root -g root -m 0700 "$OWP_FW_STATE"
  exec 8>"${OWP_FW_STATE}/lock"
  flock -w 30 8 || owp_die "firewall_busy" "another firewall change is in progress"
}

owp_firewall_loaded() {
  nft list table "$OWP_FW_TABLE" >/dev/null 2>&1
}

# Joins validated port numbers for the template, e.g. "22, 2222".
owp_join_ports() {
  local IFS=,
  local joined="$*"
  printf '%s\n' "${joined//,/, }"
}

# Sets OWP_FW_SSH_PORTS to the SSH ports of the running sshd configuration,
# joined for the template. A custom port can then not lock out the panel or
# the admin. A global instead of output, so owp_die is not swallowed by a
# command substitution.
owp_firewall_ssh_ports() {
  local sshd_config
  local -a ssh_ports=()
  sshd_config="$(sshd -T)" || owp_die "ssh_port_unknown" "could not read the sshd configuration"
  mapfile -t ssh_ports < <(owp_parse_ssh_ports <<<"$sshd_config")
  [[ "${#ssh_ports[@]}" -gt 0 ]] || owp_die "ssh_port_unknown" "no SSH port found"
  # shellcheck disable=SC2034  # read by the callers
  OWP_FW_SSH_PORTS="$(owp_join_ports "${ssh_ports[@]}")"
}

# Checks one line of the rules list and prints the nft rule for it. The
# characters are checked here; nft -c then rejects malformed addresses.
# Usage: owp_firewall_rule <line> <number>
owp_firewall_rule() {
  local line="$1" number="$2" proto port source from to family="" rest
  IFS=$'\t' read -r proto port source rest <<<"$line"
  [[ -z "${rest:-}" ]] || return 1
  [[ "$proto" == "tcp" || "$proto" == "udp" ]] || return 1
  if [[ "$port" =~ ^([0-9]{1,5})(-([0-9]{1,5}))?$ ]]; then
    from="${BASH_REMATCH[1]}"
    to="${BASH_REMATCH[3]:-$from}"
    ((10#$from >= 1 && 10#$to <= 65535 && 10#$from <= 10#$to)) || return 1
    port="$((10#$from))"
    ((10#$to == 10#$from)) || port="${port}-$((10#$to))"
  else
    return 1
  fi
  if [[ "$source" == "any" ]]; then
    source=""
  elif [[ "$source" =~ ^[0-9.]+/[0-9]{1,2}$ ]]; then
    family="ip"
  elif [[ "$source" =~ ^[0-9a-fA-F:]+/[0-9]{1,3}$ && "$source" == *:* ]]; then
    family="ip6"
  else
    return 1
  fi
  printf '        %s%s dport %s accept comment "OWPanel rule %d"\n' \
    "${family:+${family} saddr ${source} }" "$proto" "$port" "$number"
}

# Renders the complete rule set: fixed ports from the template plus the own
# rules from a list file.
# Usage: owp_firewall_render <output> <ssh ports> [<rules list>]
owp_firewall_render() {
  local output="$1" ports="$2" list="${3:-}" line rules="" number=0 rule
  if [[ -n "$list" && -s "$list" ]]; then
    while IFS= read -r line || [[ -n "$line" ]]; do
      [[ -n "$line" ]] || continue
      number=$((number + 1))
      ((number <= OWP_FW_MAX_RULES)) || owp_die "too_many_rules" "at most ${OWP_FW_MAX_RULES} firewall rules"
      # owp_firewall_rule only returns 1, it never calls owp_die.
      rule="$(owp_firewall_rule "$line" "$number")" || owp_die "invalid_rule" "firewall rule ${number} is invalid"
      rules+="${rule}"$'\n'
    done <"$list"
  fi
  [[ -n "$rules" ]] || rules=$'        # no own rules\n'
  owp_render "${OWP_FW_TEMPLATE:-${OWP_ROOT}/templates/nftables/owpanel.nft}" "$output" \
    "SSH_PORTS=${ports}" "OWN_RULES=${rules%$'\n'}"
}

# Returns 0 if nothing has to change: the rules are saved, loaded and no
# earlier change waits for confirmation.
owp_firewall_unchanged() {
  local rules="$1"
  [[ -f "$OWP_FW_SAVED" ]] && cmp -s "$rules" "$OWP_FW_SAVED" \
    && owp_firewall_loaded && [[ ! -e "${OWP_FW_STATE}/pending.nft" ]]
}

# Usage: owp_firewall_apply <rules file> [<own rules list>]
# Returns 0 when nothing changed, 10 when a confirmation is now required.
# The list of own rules is saved together with the rules on confirmation.
owp_firewall_apply() {
  local rules="$1" list="${2:-}"
  nft -c -f "$rules" || owp_die "firewall_rules_invalid" "nft rejected the firewall rules"

  owp_firewall_lock
  if owp_firewall_unchanged "$rules"; then
    owp_log "unchanged: firewall"
    return 0
  fi

  # An earlier unconfirmed change keeps its original snapshot.
  if [[ ! -e "${OWP_FW_STATE}/pending.nft" ]]; then
    rm -f "${OWP_FW_STATE}/previous.nft"
    if owp_firewall_loaded; then
      {
        printf 'table %s\ndelete table %s\n' "$OWP_FW_TABLE" "$OWP_FW_TABLE"
        nft list table "$OWP_FW_TABLE"
      } >"${OWP_FW_STATE}/previous.nft"
    fi
  fi
  install -o root -g root -m 0600 "$rules" "${OWP_FW_STATE}/pending.nft"
  if [[ -n "$list" ]]; then
    install -o root -g root -m 0600 "$list" "${OWP_FW_STATE}/pending.list"
  else
    rm -f "${OWP_FW_STATE}/pending.list"
  fi

  systemctl stop "${OWP_FW_TIMER}.timer" >/dev/null 2>&1 || true
  systemctl reset-failed "${OWP_FW_TIMER}.service" "${OWP_FW_TIMER}.timer" >/dev/null 2>&1 || true
  systemd-run --quiet --unit="$OWP_FW_TIMER" --on-active="$OWP_FW_TIMEOUT" \
    --timer-property=AccuracySec=1s \
    /bin/bash "${OWP_ROOT}/provision/firewall-rollback" \
    || owp_die "firewall_timer_failed" "could not arm the rollback timer"

  if ! nft -f "${OWP_FW_STATE}/pending.nft"; then
    # The rollback takes the lock itself.
    exec 8>&-
    /bin/bash "${OWP_ROOT}/provision/firewall-rollback"
    owp_die "firewall_load_failed" "nft could not load the rules, previous rules restored"
  fi
  owp_log "loaded: firewall, confirm within ${OWP_FW_TIMEOUT}s with firewall-confirm"
  return 10
}

# Blocklists (E-170): addresses from lists the panel fetches, dropped in an
# own table inet owpanel_block before the rules of inet owpanel. Its chain
# only drops; exceptions only skip the drop and open nothing. The table is
# not part of the rollback above: it changes no port, and the panel's own
# addresses are always among the exceptions. Every dropped address lands
# in hits4/hits6 with a counter; a reload swaps only the lists and the
# rules, so these counts survive updates of the lists. An address leaves
# them after seven days without a hit.
readonly OWP_BL_TABLE="inet owpanel_block"
# shellcheck disable=SC2034  # used by server-blocklist
readonly OWP_BL_SAVED="/etc/owpanel/blocklist.nft"
readonly OWP_BL_UNIT="owpanel-blocklist.service"
readonly OWP_BL_MAX_BLOCK=250000
readonly OWP_BL_MAX_ALLOW=200
readonly OWP_BL_HITS_SIZE=65535
readonly OWP_BL_HITS_TIMEOUT=604800

# Checks the lines "block<TAB>network" and "allow<TAB>network" and writes
# the table, in awk because lists have up to 250000 lines. Entries are an
# IPv4 network with prefix 8 to 32 or an IPv6 network with prefix 16 to 128,
# as the panel writes them; nft -c rejects anything malformed that passes.
# Sets OWP_BL_COUNT_BLOCK and OWP_BL_COUNT_ALLOW.
# Usage: owp_blocklist_render <input> <output>
owp_blocklist_render() {
  local input="$1" output="$2" counts rc=0
  counts="$(awk -v max_block="$OWP_BL_MAX_BLOCK" -v max_allow="$OWP_BL_MAX_ALLOW" -v table="$OWP_BL_TABLE" -v out="$output" \
    -v hits_size="$OWP_BL_HITS_SIZE" -v hits_timeout="$OWP_BL_HITS_TIMEOUT" '
    function v4(e,   p, o, n, i) {
      if (split(e, p, "/") != 2 || p[2] !~ /^[0-9]+$/ || p[2] + 0 < 8 || p[2] + 0 > 32) return 0
      n = split(p[1], o, ".")
      if (n != 4) return 0
      for (i = 1; i <= 4; i++) {
        if (o[i] !~ /^(0|[1-9][0-9]?[0-9]?)$/ || o[i] + 0 > 255) return 0
      }
      return 1
    }
    function v6(e,   p) {
      if (split(e, p, "/") != 2 || p[2] !~ /^[1-9][0-9]?[0-9]?$/ || p[2] + 0 < 16 || p[2] + 0 > 128) return 0
      return p[1] ~ /^[0-9a-f:]+$/ && p[1] ~ /:/ && length(p[1]) <= 39
    }
    function elements(name, list, count,   i) {
      if (count == 0) return
      printf "add element %s %s {\n", table, name > out
      for (i = 1; i < count; i++) printf "    %s,\n", list[i] > out
      printf "    %s\n}\n", list[count] > out
    }
    function fail(code) { print code; failed = 1; exit 1 }
    BEGIN { FS = "\t"; nb4 = nb6 = na4 = na6 = 0 }
    $0 == "" { next }
    NF != 2 { fail("invalid_entry") }
    {
      family = v4($2) ? 4 : (v6($2) ? 6 : 0)
      if (family == 0) { fail("invalid_entry") }
      if ($1 == "block") {
        if (++blocked > max_block) { fail("too_many_entries") }
        if (family == 4) b4[++nb4] = $2; else b6[++nb6] = $2
      } else if ($1 == "allow") {
        if (++allowed > max_allow) { fail("too_many_entries") }
        if (family == 4) a4[++na4] = $2; else a6[++na6] = $2
      } else { fail("invalid_entry") }
    }
    END {
      if (failed) exit 1
      printf "# Managed by OWPanel. Do not edit, changes are overwritten.\n# Blocklists from the panel (E-170), loaded in one step. The hit sets\n# keep their counts; only lists and rules are replaced.\n" > out
      printf "table %s {\n", table > out
      printf "    set allow4 { type ipv4_addr; flags interval; auto-merge; }\n    set allow6 { type ipv6_addr; flags interval; auto-merge; }\n" > out
      printf "    set block4 { type ipv4_addr; flags interval; auto-merge; }\n    set block6 { type ipv6_addr; flags interval; auto-merge; }\n" > out
      printf "    set hits4 { type ipv4_addr; size %d; flags dynamic,timeout; timeout %ds; }\n", hits_size, hits_timeout > out
      printf "    set hits6 { type ipv6_addr; size %d; flags dynamic,timeout; timeout %ds; }\n", hits_size, hits_timeout > out
      printf "    chain input { type filter hook input priority filter - 10; policy accept; }\n}\n" > out
      printf "flush chain %s input\n", table > out
      printf "flush set %s allow4\nflush set %s allow6\nflush set %s block4\nflush set %s block6\n", table, table, table, table > out
      elements("allow4", a4, na4)
      elements("allow6", a6, na6)
      elements("block4", b4, nb4)
      elements("block6", b6, nb6)
      printf "add rule %s input iifname \"lo\" accept\n", table > out
      printf "add rule %s input ct state established,related accept\n", table > out
      printf "add rule %s input ip saddr @allow4 accept\n", table > out
      printf "add rule %s input ip6 saddr @allow6 accept\n", table > out
      printf "add rule %s input ip saddr @block4 update @hits4 { ip saddr counter } counter drop comment \"OWPanel blocklists\"\n", table > out
      printf "add rule %s input ip6 saddr @block6 update @hits6 { ip6 saddr counter } counter drop comment \"OWPanel blocklists\"\n", table > out
      print (nb4 + nb6) " " (na4 + na6)
    }
  ' "$input")" || rc=$?
  if [[ "$rc" -ne 0 ]]; then
    case "$counts" in
      too_many_entries) owp_die "too_many_entries" "at most ${OWP_BL_MAX_BLOCK} blocked networks and ${OWP_BL_MAX_ALLOW} exceptions" ;;
      *) owp_die "invalid_entry" "invalid blocklist line" ;;
    esac
  fi
  # shellcheck disable=SC2034  # read by server-blocklist
  read -r OWP_BL_COUNT_BLOCK OWP_BL_COUNT_ALLOW <<<"$counts"
}

# Prints the hits of the blocklists as JSON fields for owp_result_ok: how
# many addresses were dropped, how many packets and bytes, and the most
# frequent ones as [address, packets, bytes, seconds since the last hit].
# "counting" is false while the table is missing or still has the layout
# without hit sets; the panel then loads the lists again.
# Usage: owp_blocklist_hits <top>
owp_blocklist_hits() {
  local top="$1" hits4 hits6 json counting=true
  # Each set is listed once; with up to 65535 addresses that takes a moment.
  hits4="$(nft -j list set "$OWP_BL_TABLE" hits4 2>/dev/null)" || { counting=false; hits4=""; }
  hits6="$(nft -j list set "$OWP_BL_TABLE" hits6 2>/dev/null)" || hits6=""
  json="$(
    printf '%s\n%s\n' "$hits4" "$hits6" | jq -cs --argjson top "$top" --argjson timeout "$OWP_BL_HITS_TIMEOUT" '
      [ .[] | .nftables[]? | .set? // empty | .elem[]? | .elem? // empty
        | select(.val | type == "string")
        | { ip: .val, p: (.counter.packets // 0), b: (.counter.bytes // 0), e: (.expires // $timeout) } ]
      | { addresses: length,
          packets: (map(.p) | add // 0),
          bytes: (map(.b) | add // 0),
          top: (sort_by(-.p) | .[:$top] | map([.ip, .p, .b, ([$timeout - .e, 0] | max)])) }'
  )" || owp_die "blocklist_read_failed" "could not read the blocklist hits"
  [[ "$json" == \{*\} ]] || owp_die "blocklist_read_failed" "unexpected answer from jq"
  printf '%s,"counting":%s' "${json:1:${#json}-2}" "$counting"
}

# Installs and enables the unit that loads the saved blocklists at boot.
owp_blocklist_unit() {
  local template="${OWP_BL_UNIT_TEMPLATE:-${OWP_ROOT}/templates/systemd/${OWP_BL_UNIT}}"
  local unit="/etc/systemd/system/${OWP_BL_UNIT}"
  if ! cmp -s "$template" "$unit"; then
    install -o root -g root -m 0644 "$template" "$unit"
    systemctl daemon-reload
  fi
  systemctl enable "$OWP_BL_UNIT" >/dev/null 2>&1 \
    || owp_die "firewall_unit_failed" "could not enable ${OWP_BL_UNIT}"
}
