#!/usr/bin/env bats
# Tests for the restic backup and restore scripts (E-096, E-098): input
# checks, the snapshot list and the file listing. The backup itself is tested against a Storage Box on the
# test VM.

ROOT="${BATS_TEST_DIRNAME}/.."

setup() {
  for script in backup-setup site-backup site-backups site-backup-files site-restore panel-backup panel-backups panel-backup-files; do
    sed "s#/opt/owpanel/lib/#${ROOT}/server/lib/#" "${ROOT}/server/bin/${script}" >"${BATS_TEST_TMPDIR}/${script}"
  done
  export OWP_BACKUP_DIR="${BATS_TEST_TMPDIR}/backup"
}

@test "backup-setup checks host, account, path and secrets before anything else" {
  run bash "${BATS_TEST_TMPDIR}/backup-setup" --host 'box;id' --account u123456 --path owpanel/a </dev/null
  [[ "${lines[-1]}" == *'"error":"invalid_host"'* ]]
  run bash "${BATS_TEST_TMPDIR}/backup-setup" --host u1.your-storagebox.de --account root --path owpanel/a </dev/null
  [[ "${lines[-1]}" == *'"error":"invalid_account"'* ]]
  for path in '../etc' '/abs' 'a/../b' 'a b' ''; do
    run bash "${BATS_TEST_TMPDIR}/backup-setup" --host u1.your-storagebox.de --account u123456 --path "$path" </dev/null
    [[ "${lines[-1]}" == *'"error":"invalid_path"'* ]] || { echo "accepted: $path"; return 1; }
  done
  run bash -c "printf 'repo\tshort\n' | bash '${BATS_TEST_TMPDIR}/backup-setup' --host u1.your-storagebox.de --account u123456-sub2 --path owpanel/a"
  [[ "${lines[-1]}" == *'"error":"invalid_input"'* ]]
  run bash -c "printf 'shell\tid\n' | bash '${BATS_TEST_TMPDIR}/backup-setup' --host u1.your-storagebox.de --account u123456 --path owpanel/a"
  [[ "${lines[-1]}" == *'"error":"invalid_input"'* ]]
  [ ! -e "$OWP_BACKUP_DIR" ]
}

@test "site-backup and site-backups check their arguments" {
  run bash "${BATS_TEST_TMPDIR}/site-backup" --user root
  [[ "${lines[-1]}" == *'"error":"invalid_user"'* ]]
  run bash "${BATS_TEST_TMPDIR}/site-backup" --user ow10000
  [[ "${lines[-1]}" == *'"error":"reserved_user"'* ]]
  run bash "${BATS_TEST_TMPDIR}/site-backup" --user ow38217 --keep-daily 0
  [[ "${lines[-1]}" == *'"error":"invalid_arguments"'* ]]
  run bash "${BATS_TEST_TMPDIR}/site-backup" --user ow38217 --keep-weekly 'x'
  [[ "${lines[-1]}" == *'"error":"invalid_arguments"'* ]]
  run bash "${BATS_TEST_TMPDIR}/site-backup" --user ow38217 --keep-hourly '24;id'
  [[ "${lines[-1]}" == *'"error":"invalid_arguments"'* ]]
  run bash "${BATS_TEST_TMPDIR}/site-backups" --user ow38217 --extra
  [[ "${lines[-1]}" == *'"error":"invalid_arguments"'* ]]
}

@test "the snapshot list keeps only known fields, newest first" {
  run php -n "${ROOT}/server/lib/backup-list.php" <<'JSON'
[{"id":"aaaaaaaa11111111aaaaaaaa11111111aaaaaaaa11111111aaaaaaaa11111111","time":"2026-11-10T02:30:00Z","tags":["site:ow38217","schedule"],"paths":["/secret"],"summary":{"total_bytes_processed":2048,"data_added":512}},
 {"id":"bbbbbbbb22222222bbbbbbbb22222222bbbbbbbb22222222bbbbbbbb22222222","time":"2026-11-11T09:00:00Z","tags":["site:ow38217","manual"]},
 {"id":"cccccccc33333333cccccccc33333333cccccccc33333333cccccccc33333333","time":"2026-11-12T08:00:00Z","tags":["site:ow38217","restore"]},
 {"id":"not-an-id","time":"x"}]
JSON
  [ "$status" -eq 0 ]
  [ "$output" = '[{"id":"cccccccc","time":"2026-11-12T08:00:00Z","size":null,"added":null,"kind":"restore"},{"id":"bbbbbbbb","time":"2026-11-11T09:00:00Z","size":null,"added":null,"kind":"manual"},{"id":"aaaaaaaa","time":"2026-11-10T02:30:00Z","size":2048,"added":512,"kind":"schedule"}]' ]
}

@test "site-backup-files and site-restore check their arguments before anything else" {
  run bash "${BATS_TEST_TMPDIR}/site-backup-files" --user ow38217 --snapshot 'abc;id'
  [[ "${lines[-1]}" == *'"error":"invalid_snapshot"'* ]]
  for path in '../etc' '/etc' 'a/../../b' 'a//b' 'a/' './a' "$(printf 'a\nb')"; do
    run bash "${BATS_TEST_TMPDIR}/site-backup-files" --user ow38217 --snapshot 2e2b9c0c --path "$path"
    [[ "${lines[-1]}" == *'"error":"invalid_path"'* ]] || { echo "accepted: $path"; return 1; }
    run bash "${BATS_TEST_TMPDIR}/site-restore" --user ow38217 --snapshot 2e2b9c0c --scope path --path "$path"
    [[ "${lines[-1]}" == *'"error":"invalid_path"'* ]] || { echo "restore accepted: $path"; return 1; }
  done
  run bash "${BATS_TEST_TMPDIR}/site-restore" --user ow10000 --snapshot 2e2b9c0c --scope all
  [[ "${lines[-1]}" == *'"error":"reserved_user"'* ]]
  run bash "${BATS_TEST_TMPDIR}/site-restore" --user ow38217 --snapshot 2e2b9c0c --scope everything
  [[ "${lines[-1]}" == *'"error":"invalid_arguments"'* ]]
  run bash "${BATS_TEST_TMPDIR}/site-restore" --user ow38217 --snapshot 2e2b9c0c --scope all --path htdocs
  [[ "${lines[-1]}" == *'"error":"invalid_arguments"'* ]]
  run bash "${BATS_TEST_TMPDIR}/site-restore" --user ow38217 --snapshot 2e2b9c0c --scope path
  [[ "${lines[-1]}" == *'"error":"invalid_arguments"'* ]]
  run bash "${BATS_TEST_TMPDIR}/site-restore" --user ow38217 --snapshot 2e2b9c0c --scope database --database ow38218_shop
  [[ "${lines[-1]}" == *'"error":"invalid_database"'* ]]
  run bash "${BATS_TEST_TMPDIR}/site-restore" --user ow38217 --snapshot 2e2b9c0c --scope database --database 'ow38217_a;drop'
  [[ "${lines[-1]}" == *'"error":"invalid_database"'* ]]
}

@test "the file listing keeps the direct entries of one directory, directories first" {
  run php -n "${ROOT}/server/lib/backup-files.php" /var/www/ow38217/htdocs <<'JSON'
{"struct_type":"snapshot","paths":["/var/www/ow38217"]}
{"name":"htdocs","type":"dir","path":"/var/www/ow38217/htdocs","struct_type":"node"}
{"name":"index.php","type":"file","path":"/var/www/ow38217/htdocs/index.php","size":12,"uid":1,"mtime":"2026-11-11T13:22:43.109018375+01:00","struct_type":"node"}
{"name":"wp-content","type":"dir","path":"/var/www/ow38217/htdocs/wp-content","mtime":"2026-11-10T10:00:00+01:00","struct_type":"node"}
{"name":"deep.txt","type":"file","path":"/var/www/ow38217/htdocs/wp-content/deep.txt","size":1,"struct_type":"node"}
{"name":"link","type":"symlink","path":"/var/www/ow38217/htdocs/link","linktarget":"/etc/passwd","struct_type":"node"}
not json
JSON
  [ "$status" -eq 0 ]
  [ "$output" = '{"entries":[{"name":"wp-content","type":"dir","size":null,"mtime":"2026-11-10T10:00:00"},{"name":"index.php","type":"file","size":12,"mtime":"2026-11-11T13:22:43"},{"name":"link","type":"symlink","size":null,"mtime":null}],"more":false}' ]
}

@test "panel-backup and panel-backups check their arguments" {
  run bash "${BATS_TEST_TMPDIR}/panel-backup" --keep-daily 0
  [[ "${lines[-1]}" == *'"error":"invalid_arguments"'* ]]
  run bash "${BATS_TEST_TMPDIR}/panel-backup" --keep-monthly 'x;id'
  [[ "${lines[-1]}" == *'"error":"invalid_arguments"'* ]]
  run bash "${BATS_TEST_TMPDIR}/panel-backup" --keep-hourly -1
  [[ "${lines[-1]}" == *'"error":"invalid_arguments"'* ]]
  run bash "${BATS_TEST_TMPDIR}/panel-backup" --path /etc
  [[ "${lines[-1]}" == *'"error":"invalid_arguments"'* ]]
  run bash "${BATS_TEST_TMPDIR}/panel-backups" --tag site:ow38217
  [[ "${lines[-1]}" == *'"error":"invalid_arguments"'* ]]
  run bash "${BATS_TEST_TMPDIR}/panel-backup-files" --snapshot 'abc;id'
  [[ "${lines[-1]}" == *'"error":"invalid_snapshot"'* ]]
  run bash "${BATS_TEST_TMPDIR}/panel-backup-files" --path /etc
  [[ "${lines[-1]}" == *'"error":"invalid_arguments"'* ]]
}

@test "the file listing can list every file of a snapshot with its path" {
  run php -n "${ROOT}/server/lib/backup-files.php" --files <<'JSON'
{"struct_type":"snapshot"}
{"name":"var","type":"dir","path":"/var","struct_type":"node"}
{"name":"owpanel.sql","type":"file","path":"/var/backups/owpanel/panel/owpanel.sql","size":8000,"mtime":"2026-11-12T02:30:01+01:00","struct_type":"node"}
{"name":".env","type":"file","path":"/var/www/ow10000/shared/.env","size":900,"struct_type":"node"}
JSON
  [ "$status" -eq 0 ]
  [ "$output" = '{"entries":[{"name":"/var/backups/owpanel/panel/owpanel.sql","type":"file","size":8000,"mtime":"2026-11-12T02:30:01"},{"name":"/var/www/ow10000/shared/.env","type":"file","size":900,"mtime":null}],"more":false}' ]
}

@test "the panel restore checks its inputs and what the backup holds" {
  source "${ROOT}/server/lib/common.sh"
  source "${ROOT}/server/lib/install.sh"
  source "${ROOT}/server/lib/panel-restore.sh"
  owp_restore_valid_host u123456.your-storagebox.de
  ! owp_restore_valid_host 'box;id'
  owp_restore_valid_account u123456-sub1
  ! owp_restore_valid_account root
  owp_restore_valid_path restic
  ! owp_restore_valid_path ../etc

  local dir="${BATS_TEST_TMPDIR}/restored"
  mkdir -p "${dir}/var/backups/owpanel/panel" "${dir}/var/www/ow10000/shared" "${dir}/etc/owpanel"
  printf 'CREATE DATABASE /*!32312 IF NOT EXISTS*/ `owpanel`;\nUSE `owpanel`;\n' >"${dir}/var/backups/owpanel/panel/owpanel.sql"
  ! owp_restore_check "$dir"
  echo 'APP_KEY=base64:x' >"${dir}/var/www/ow10000/shared/.env"
  owp_restore_check "$dir"
  printf 'DOMAIN=panel.example.de\n' >"${dir}/etc/owpanel/panel.conf"
  [ "$(owp_restore_setting "$dir" DOMAIN)" = panel.example.de ]
  echo 'USE `mysql`;' >>"${dir}/var/backups/owpanel/panel/owpanel.sql"
  ! owp_restore_check "$dir"
}

@test "the installer lists panel backups newest first and fetches the chosen one" {
  source "${ROOT}/server/lib/common.sh"
  source "${ROOT}/server/lib/install.sh"
  source "${ROOT}/server/lib/panel-restore.sh"
  local bin="${BATS_TEST_TMPDIR}/bin" work="${BATS_TEST_TMPDIR}/work"
  mkdir -p "$bin" "$work"
  cat >"${bin}/restic" <<'STUB'
#!/bin/bash
printf '%s\n' "$*" >>"${STUB_LOG}"
if [[ " $* " == *" snapshots "* ]]; then
  printf '[{"short_id":"11111111","time":"2026-11-10T01:30:02.1+01:00"},{"short_id":"33333333","time":"2026-11-12T19:00:05.9+01:00"},{"short_id":"bad;id","time":"2026-11-11T01:30:00Z"},{"short_id":"22222222","time":"2026-11-11T01:30:00Z"}]'
fi
STUB
  chmod +x "${bin}/restic"
  export STUB_LOG="${work}/calls"

  run env PATH="${bin}:${PATH}" bash -c "source ${ROOT}/server/lib/common.sh; source ${ROOT}/server/lib/install.sh; source ${ROOT}/server/lib/panel-restore.sh; owp_restore_list box.example.test u1-sub1 restic pw ${work}"
  [ "$status" -eq 0 ]
  [ "${lines[0]}" = "33333333 2026-11-12 18:00" ]
  [ "${lines[1]}" = "22222222 2026-11-11 01:30" ]
  [ "${lines[2]}" = "11111111 2026-11-10 00:30" ]
  [ "${#lines[@]}" -eq 3 ]

  PATH="${bin}:${PATH}" owp_restore_fetch box.example.test u1-sub1 restic pw "$work" 22222222 "${work}/files"
  grep -q 'restore 22222222 --tag panel' "${work}/calls"
  grep -q 'sshpass -f '"${work}"'/box-password ssh -p 23' "${work}/calls"
  run owp_restore_fetch box.example.test u1-sub1 restic pw "$work" 'latest;id' "${work}/files"
  [ "$status" -ne 0 ]
}

@test "the installer accepts the home directory as repository and tests the login first" {
  source "${ROOT}/server/lib/common.sh"
  source "${ROOT}/server/lib/install.sh"
  source "${ROOT}/server/lib/panel-restore.sh"
  [ "$(owp_restore_repository_path restic)" = restic ]
  [ "$(owp_restore_repository_path owpanel/01m72kfs7fqajkm64g4rnf8n2m)" = restic ]
  [ "$(owp_restore_repository_path owpanel/01m72kfs7fqajkm64g4rnf8n2m/restic)" = restic ]
  [ "$(owp_restore_repository_path backups/panel)" = backups/panel ]

  local bin="${BATS_TEST_TMPDIR}/bin" work="${BATS_TEST_TMPDIR}/work"
  mkdir -p "$bin" "$work"
  printf '#!/bin/bash\nexit "${STUB_STATUS}"\n' >"${bin}/sshpass"
  chmod +x "${bin}/sshpass"
  [ "$(PATH="${bin}:${PATH}" STUB_STATUS=0 owp_restore_probe box.example.test u1-sub1 "$work")" = ok ]
  [ "$(PATH="${bin}:${PATH}" STUB_STATUS=5 owp_restore_probe box.example.test u1-sub1 "$work")" = password ]
  [ "$(PATH="${bin}:${PATH}" STUB_STATUS=255 owp_restore_probe box.example.test u1-sub1 "$work")" = unreachable ]
}

@test "site-restore accepts latest as snapshot" {
  run bash "${BATS_TEST_TMPDIR}/site-restore" --user ow38217 --snapshot latest --scope everything
  [[ "${lines[-1]}" == *'"error":"invalid_arguments"'* ]]
  run bash "${BATS_TEST_TMPDIR}/site-restore" --user ow38217 --snapshot newest --scope all
  [[ "${lines[-1]}" == *'"error":"invalid_snapshot"'* ]]
}

@test "the newest scheduled or manual backup of a website is found, safety backups are not" {
  local bin="${BATS_TEST_TMPDIR}/bin"
  mkdir -p "$bin" "$OWP_BACKUP_DIR"
  printf 'sftp:test:/restic\n' >"${OWP_BACKUP_DIR}/repository"
  # restic lists oldest first; it is asked for scheduled and manual ones only.
  cat >"${bin}/restic" <<'STUB'
#!/usr/bin/env bash
[[ " $* " == *" --tag site:ow38217,schedule --tag site:ow38217,manual "* ]] || { echo "unexpected: $*" >&2; exit 1; }
[[ -n "${EMPTY:-}" ]] && { echo '[]'; exit 0; }
printf '[{"time":"2026-11-12T02:30:00Z","tree":"%s","id":"%s","short_id":"aaaaaaaa"},' "$(printf 'c%.0s' {1..64})" "$(printf 'a%.0s' {1..64})"
printf '{"time":"2026-11-13T02:30:00Z","parent":"%s","id":"%s","short_id":"bbbbbbbb"}]\n' "$(printf 'a%.0s' {1..64})" "$(printf 'b%.0s' {1..64})"
STUB
  chmod +x "${bin}/restic"
  run bash -c "source '${ROOT}/server/lib/common.sh'; source '${ROOT}/server/lib/backup.sh'; source '${ROOT}/server/lib/site.sh'; source '${ROOT}/server/lib/database.sh'; source '${ROOT}/server/lib/site-backup.sh'
    OWP_RESTIC_BIN='${bin}/restic'; owp_site_latest_snapshot ow38217; echo \"latest=\${OWP_SNAPSHOT_ID}\""
  [ "$status" -eq 0 ]
  [ "${lines[-1]}" = "latest=$(printf 'b%.0s' {1..64})" ]
  EMPTY=1 run bash -c "source '${ROOT}/server/lib/common.sh'; source '${ROOT}/server/lib/backup.sh'; source '${ROOT}/server/lib/site.sh'; source '${ROOT}/server/lib/database.sh'; source '${ROOT}/server/lib/site-backup.sh'
    OWP_RESTIC_BIN='${bin}/restic'; owp_site_latest_snapshot ow38217; echo \"latest=\${OWP_SNAPSHOT_ID}\""
  [ "$status" -eq 0 ]
  [ "${lines[-1]}" = "latest=" ]
}
