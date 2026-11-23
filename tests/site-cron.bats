#!/usr/bin/env bats
# Tests for server/bin/site-cron. Writes into a temporary cron directory.

ROOT="${BATS_TEST_DIRNAME}/.."

setup() {
  SCRIPT="${BATS_TEST_TMPDIR}/site-cron"
  sed "s#/opt/owpanel/lib/#${ROOT}/server/lib/#" "${ROOT}/server/bin/site-cron" >"$SCRIPT"
  export OWP_CRON_DIR="${BATS_TEST_TMPDIR}/cron.d"
  export OWP_SITES_ROOT="/var/www"
  export OWP_CRON_BIN="/bin/true"
  mkdir -p "$OWP_CRON_DIR"
}

cron_file() {
  cat "${OWP_CRON_DIR}/owpanel-ow38217"
}

@test "site-cron writes the jobs of a website" {
  getent passwd ow38217 >/dev/null || skip "needs the test user ow38217"
  run bash -c "printf '*/5 * * * *\tphp artisan schedule:run\n0 3 * * 1-5\tdate +%%d.%%m. > /tmp/x\n' | bash '$SCRIPT' --user ow38217"
  [ "$status" -eq 0 ]
  [ "${lines[-1]}" = '{"ok":true,"script":"site-cron","jobs":2}' ]
  run cron_file
  [[ "$output" == *'*/5 * * * * ow38217 cd /var/www/ow38217/htdocs && ( php artisan schedule:run ) 2>&1 | logger -t owpanel-cron-ow38217'* ]]
  [[ "$output" == *'date +\%d.\%m.'* ]]
  [[ "$output" == *'MAILTO=""'* ]]
  # php runs in the version of the website (E-172).
  [[ "$output" == *'PATH=/var/lib/owpanel/php/ow38217:/usr/local/bin:/usr/bin:/bin'* ]]
}

@test "site-cron removes the file when no job is left" {
  printf 'x\n' >"${OWP_CRON_DIR}/owpanel-ow38217"
  run bash "$SCRIPT" --user ow38217 </dev/null
  [ "$status" -eq 0 ]
  [ ! -e "${OWP_CRON_DIR}/owpanel-ow38217" ]
  [ "${lines[-1]}" = '{"ok":true,"script":"site-cron","jobs":0}' ]
}

@test "site-cron rejects bad time fields and commands" {
  local schedule
  for schedule in '* * * *' '* * * * * *' '61x * * * *' '@reboot' '* * * * mon' '$(id) * * * *'; do
    run bash -c "printf '%s\tdate\n' '$schedule' | bash '$SCRIPT' --user ow38217"
    [ "$status" -eq 1 ]
    [[ "${lines[-1]}" == *'"error":"invalid_cron_schedule"'* ]] || { echo "accepted: $schedule"; return 1; }
  done

  run bash -c "printf '* * * * *\t   \n' | bash '$SCRIPT' --user ow38217"
  [[ "${lines[-1]}" == *'"error":"invalid_cron_command"'* ]]
  run bash -c "printf '* * * * *\tdate\r\n' | bash '$SCRIPT' --user ow38217"
  [[ "${lines[-1]}" == *'"error":"invalid_cron_command"'* ]]
  run bash -c "printf '* * * * * date\n' | bash '$SCRIPT' --user ow38217"
  [[ "${lines[-1]}" == *'"error":"invalid_cron_job"'* ]]
  [ ! -e "${OWP_CRON_DIR}/owpanel-ow38217" ]
}

@test "site-cron refuses the panel user and invalid users" {
  run bash "$SCRIPT" --user ow10000 </dev/null
  [[ "${lines[-1]}" == *'"error":"reserved_user"'* ]]
  run bash "$SCRIPT" --user root </dev/null
  [[ "${lines[-1]}" == *'"error":"invalid_user"'* ]]
}
