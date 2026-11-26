#!/usr/bin/env bash
# Second half of panel-update (E-185): runs as the transient unit
# owpanel-update, started by bin/panel-update after it checked the archive.
# panel-update runs a copy of this file, because the installer replaces
# /opt/owpanel/lib on the way.
#
#   run.sh <version> <installed version>
#
# Stops the scheduler, waits up to ten minutes for running server tasks,
# stops the queue worker and runs the installer of the new release, which
# starts both again. Pending tasks stay in the queue and run afterwards.
set -euo pipefail

readonly UPDATE_DIR="${OWP_UPDATE_DIR:-/var/lib/owpanel/update}"
readonly RELEASES="/var/www/ow10000/releases"
readonly PANEL_GROUP="ow10000"
readonly WAIT_SECONDS=600
readonly VERSION_RE='^[0-9]{1,4}\.[0-9]{1,4}\.[0-9]{1,4}(-[0-9A-Za-z.]{1,32})?$'
readonly ANY_VERSION_RE='^([0-9]{1,4}\.[0-9]{1,4}\.[0-9]{1,4}(-[0-9A-Za-z.]{1,32})?|dev-[0-9a-f]{7,40})$'

TARGET="${1:-}"
FROM="${2:-}"
STARTED=""
LOG="${UPDATE_DIR}/update.log"

now() { date -u +%Y-%m-%dT%H:%M:%SZ; }

log() { printf '%s %s\n' "$(now)" "$*" | tee -a "$LOG"; }

write_status() {
  local tmp="${UPDATE_DIR}/.status.new"
  printf 'state=%s\nversion=%s\nfrom=%s\nstarted_at=%s\nfinished_at=%s\nerror=%s\n' \
    "$1" "$TARGET" "$FROM" "$STARTED" "$(now)" "${2:-}" >"$tmp"
  chmod 0600 "$tmp"
  mv -f "$tmp" "${UPDATE_DIR}/status"
}

# Whatever happens, the panel keeps its worker and scheduler.
start_panel() {
  systemctl start owpanel-queue.target owpanel-scheduler.timer >/dev/null 2>&1 || true
}

fail() {
  log "update failed: $1"
  start_panel
  write_status failed "$1"
  exit 1
}

running_tasks() {
  mariadb --protocol=socket -N -B owpanel -e "SELECT COUNT(*) FROM server_tasks WHERE status = 'running'" 2>/dev/null || echo 0
}

# Keeps archive, checksum and signature next to the release, readable by
# the panel, which sends them to its servers (server-scripts-update, E-167).
keep_archive() {
  local work="$1" target="${RELEASES}/${TARGET}" file
  [[ -d "$target" ]] || return 0
  for file in "owpanel-${TARGET}.tar.gz" "owpanel-${TARGET}.tar.gz.sha256" "owpanel-${TARGET}.tar.gz.sig"; do
    install -o root -g "$PANEL_GROUP" -m 0640 "${work}/${file}" "${target}/${file}"
  done
}

main() {
  local work source waited=0 open
  export PATH="/usr/sbin:/usr/bin:/sbin:/bin"
  export LC_ALL="C.UTF-8"
  [[ "$TARGET" =~ $VERSION_RE && "$FROM" =~ $ANY_VERSION_RE ]] || exit 2
  work="${UPDATE_DIR}/${TARGET}"
  source="${work}/owpanel-${TARGET}"
  STARTED="$(sed -n 's/^started_at=//p' "${UPDATE_DIR}/status" 2>/dev/null | tail -n 1)"
  [[ -n "$STARTED" ]] || STARTED="$(now)"
  : >"$LOG"
  chmod 0600 "$LOG"
  [[ -x "${source}/install" ]] || fail "not_a_release"

  log "update from ${FROM} to ${TARGET}"
  # No new scheduled tasks, such as backups, from here on.
  systemctl stop owpanel-scheduler.timer || true
  while open="$(running_tasks)" && [[ "$open" =~ ^[0-9]+$ && "$open" -gt 0 ]]; do
    if ((waited >= WAIT_SECONDS)); then
      fail "tasks_running"
    fi
    ((waited % 60 == 0)) && log "waiting for ${open} running task(s)"
    sleep 5
    waited=$((waited + 5))
  done
  systemctl stop owpanel-queue.target || true

  log "running the installer of ${TARGET}"
  if ! (cd "$source" && ./install --no-reboot --skip-dns-check) >>"$LOG" 2>&1; then
    fail "install_failed"
  fi
  keep_archive "$work" || log "could not keep the archive next to the release"
  rm -rf -- "$work"
  start_panel
  write_status succeeded
  log "update to ${TARGET} done"
}

main "$@"
