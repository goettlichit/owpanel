# shellcheck shell=bash
# Backing up one website with restic (E-096), shared by site-backup and,
# as the safety backup before a restore, by site-restore (E-098). Needs
# common.sh, site.sh, database.sh and backup.sh; the caller holds the lock.

OWP_SITES_ROOT="${OWP_SITES_ROOT:-/var/www}"

# Dumps each database of the website into a directory. Prints only the
# number of dumps; its log lines go to stderr.
owp_dump_site_databases() {
  local user="$1" dir="$2" name count=0
  if ! owp_mariadb_running; then
    owp_log "MariaDB is not running; no database dumps" >&2
    echo 0
    return 0
  fi
  while read -r name; do
    [[ -n "$name" ]] || continue
    owp_log "dumping ${name}" >&2
    # As MariaDB's root through the socket; --single-transaction gives a
    # consistent dump of InnoDB tables without locking the website.
    mariadb-dump --protocol=socket --single-transaction --quick --routines --triggers --events \
      --hex-blob --default-character-set=utf8mb4 --databases "$name" >"${dir}/${name}.sql" \
      || owp_die "dump_failed" "mariadb-dump failed for ${name}"
    count=$((count + 1))
  done < <(owp_mariadb --execute="SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE '${user}\\_%'" \
    | while read -r name; do
      if owp_valid_db_name "$name" && [[ "$(owp_db_site_user "$name")" == "$user" ]]; then
        printf '%s\n' "$name"
      fi
    done)
  echo "$count"
}

# Backs up the website directory (without tmp and .cache) and the dumps of
# its databases, tagged site:<user> and the kind: schedule, manual or
# restore. Sets OWP_SITE_SNAPSHOT, OWP_SITE_DATABASES, OWP_SITE_FILES and
# OWP_SITE_ADDED.
owp_site_backup() {
  local user="$1" kind="$2" root staging summary
  root="${OWP_SITES_ROOT}/${user}"
  [[ -d "$root" && ! -L "$root" ]] || owp_die "site_not_found" "website ${user} does not exist"

  staging="${OWP_BACKUP_STAGING}/${user}"
  # 0711: site users may pass through to their own restore directory
  # (site-restore), but list nothing; each entry inside is root-only.
  install -d -o root -g root -m 0711 "$OWP_BACKUP_STAGING"
  rm -rf -- "$staging"
  install -d -o root -g root -m 0700 "$staging"
  # The dumps leave the disk when the script ends, also after an error.
  OWP_CLEANUP+=("$staging")

  OWP_SITE_DATABASES="$(owp_dump_site_databases "$user" "$staging")"

  owp_tmpdir
  owp_log "backing up ${root} and ${OWP_SITE_DATABASES} database(s) (${kind})"
  if ! owp_restic backup --json --quiet --tag "site:${user},${kind}" --host "$(hostname -s)" \
    --exclude "${root}/tmp" --exclude "${root}/.cache" \
    "$root" "$staging" >"${OWP_TMPDIR}/backup.json" 2>"${OWP_TMPDIR}/restic.err"; then
    owp_die "backup_failed" "$(tail -n 1 "${OWP_TMPDIR}/restic.err")"
  fi
  summary="$(grep '"message_type":"summary"' "${OWP_TMPDIR}/backup.json" | tail -n 1)"
  OWP_SITE_SNAPSHOT="$(sed -n 's/.*"snapshot_id":"\([0-9a-f]\{8,64\}\)".*/\1/p' <<<"$summary")"
  OWP_SITE_SNAPSHOT="${OWP_SITE_SNAPSHOT:0:8}"
  # shellcheck disable=SC2034 # read by the calling script
  OWP_SITE_ADDED="$(sed -n 's/.*"data_added":\([0-9]*\).*/\1/p' <<<"$summary")"
  # shellcheck disable=SC2034 # read by the calling script
  OWP_SITE_FILES="$(sed -n 's/.*"total_files_processed":\([0-9]*\).*/\1/p' <<<"$summary")"
  rm -rf -- "$staging"
}

# Checks that a snapshot ID (8 to 64 hex digits) names a snapshot of this
# website and sets OWP_SNAPSHOT_ID to its full ID. Not in a subshell: it
# stops the script with snapshot_not_found otherwise.
owp_site_snapshot() {
  local user="$1" id="$2" full
  [[ "$id" =~ ^[0-9a-f]{8,64}$ ]] || owp_die "invalid_snapshot" "invalid snapshot ID"
  owp_tmpdir
  owp_restic snapshots --json --no-lock --tag "site:${user}" >"${OWP_TMPDIR}/snapshots.json" 2>"${OWP_TMPDIR}/restic.err" \
    || owp_die "list_failed" "$(tail -n 1 "${OWP_TMPDIR}/restic.err")"
  full="$({ grep -o '"id":"'"${id}"'[0-9a-f]*"' "${OWP_TMPDIR}/snapshots.json" || true; } | head -n 1 | sed 's/"id":"\([0-9a-f]*\)"/\1/')"
  [[ "$full" =~ ^[0-9a-f]{64}$ ]] || owp_die "snapshot_not_found" "snapshot ${id} does not belong to ${user}"
  # shellcheck disable=SC2034 # read by the calling script
  OWP_SNAPSHOT_ID="$full"
}

# A path below htdocs as the panel sends it: relative, without "." or ".."
# components, without control characters, at most 1024 bytes. Empty means
# htdocs itself.
owp_valid_site_path() {
  local path="$1" part
  [[ -z "$path" ]] && return 0
  [[ ${#path} -le 1024 && "$path" != /* && "$path" != */ ]] || return 1
  [[ ! "$path" =~ [[:cntrl:]] ]] || return 1
  local IFS=/
  for part in $path; do
    [[ -n "$part" && "$part" != "." && "$part" != ".." ]] || return 1
  done
}

# Sets OWP_SNAPSHOT_ID to the full ID of the newest scheduled or manual
# snapshot of a website, or to "" if it has none (E-106). Safety backups
# taken before a restore are left out. restic lists snapshots oldest first.
owp_site_latest_snapshot() {
  local user="$1"
  owp_tmpdir
  owp_restic snapshots --json --no-lock --tag "site:${user},schedule" --tag "site:${user},manual" \
    >"${OWP_TMPDIR}/snapshots.json" 2>"${OWP_TMPDIR}/restic.err" \
    || owp_die "list_failed" "$(tail -n 1 "${OWP_TMPDIR}/restic.err")"
  # shellcheck disable=SC2034 # read by the calling script
  OWP_SNAPSHOT_ID="$({ grep -o '"id":"[0-9a-f]\{64\}"' "${OWP_TMPDIR}/snapshots.json" || true; } | tail -n 1 | sed 's/"id":"\([0-9a-f]*\)"/\1/')"
}
