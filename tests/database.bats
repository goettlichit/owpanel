#!/usr/bin/env bats
# Tests for server/lib/database.sh, db-create, db-delete and db-query. They run
# without root and stop at the input checks, before MariaDB is touched.

ROOT="${BATS_TEST_DIRNAME}/.."

setup() {
  # shellcheck source=server/lib/common.sh
  source "${ROOT}/server/lib/common.sh"
  # shellcheck source=server/lib/site.sh
  source "${ROOT}/server/lib/site.sh"
  # shellcheck source=server/lib/database.sh
  source "${ROOT}/server/lib/database.sh"
  local script
  for script in db-create db-delete db-export db-import db-query; do
    sed "s#/opt/owpanel/lib/#${ROOT}/server/lib/#" "${ROOT}/server/bin/${script}" >"${BATS_TEST_TMPDIR}/${script}"
  done
}

@test "accepts database names of site users" {
  local name
  for name in ow10001_a ow38217_shop ow59999_db_2 ow38217_0 ow38217_abcdefghijklmnop; do
    owp_valid_db_name "$name" || { echo "rejected: $name"; return 1; }
  done
}

@test "rejects other database names" {
  local name
  for name in ow10000_panel ow9999_db ow60000_db ow38217 ow38217_ ow38217__x ow38217_Shop ow38217_a-b \
    'ow38217_a;drop' ow38217_abcdefghijklmnopq owpanel mysql "ow38217_a b" 'ow38217_`x`' $'ow38217_a\nb'; do
    if owp_valid_db_name "$name"; then
      echo "accepted: $name"
      return 1
    fi
  done
}

@test "the site user is the prefix of the name" {
  [ "$(owp_db_site_user ow38217_shop_2)" = "ow38217" ]
}

@test "passwords are 32 to 64 letters and digits" {
  owp_valid_db_password "$(printf 'a%.0s' {1..32})"
  owp_valid_db_password "Abc123$(printf 'x%.0s' {1..58})"
  ! owp_valid_db_password "$(printf 'a%.0s' {1..31})"
  ! owp_valid_db_password "$(printf 'a%.0s' {1..65})"
  ! owp_valid_db_password "abcdefghijklmnopqrstuvwxyz012345'"
  ! owp_valid_db_password "abcdefghijklmnopqrstuvwxyz01234 5"
}

@test "db-create rejects bad names before reading anything else" {
  run bash "${BATS_TEST_TMPDIR}/db-create" --name 'ow38217_x;id' </dev/null
  [ "$status" -eq 1 ]
  [ "${lines[-1]}" = '{"ok":false,"script":"db-create","error":"invalid_database_name"}' ]
}

@test "db-create needs a valid password on stdin" {
  run bash "${BATS_TEST_TMPDIR}/db-create" --name ow38217_shop </dev/null
  [ "$status" -eq 1 ]
  [ "${lines[-1]}" = '{"ok":false,"script":"db-create","error":"invalid_password"}' ]

  run bash -c "printf 'short\n' | bash '${BATS_TEST_TMPDIR}/db-create' --name ow38217_shop"
  [ "$status" -eq 1 ]
  [ "${lines[-1]}" = '{"ok":false,"script":"db-create","error":"invalid_password"}' ]
}

@test "db-create and db-delete reject unknown parameters" {
  run bash "${BATS_TEST_TMPDIR}/db-create" --name ow38217_shop --password secret </dev/null
  [ "$status" -eq 1 ]
  [[ "${lines[-1]}" == *'"error":"invalid_arguments"'* ]]

  run bash "${BATS_TEST_TMPDIR}/db-delete"
  [ "$status" -eq 1 ]
  [[ "${lines[-1]}" == *'"error":"invalid_arguments"'* ]]

  run bash "${BATS_TEST_TMPDIR}/db-delete" --name ow10000_panel
  [ "$status" -eq 1 ]
  [ "${lines[-1]}" = '{"ok":false,"script":"db-delete","error":"invalid_database_name"}' ]
}

@test "db-query checks name and row limit before anything else" {
  run bash "${BATS_TEST_TMPDIR}/db-query" --name 'ow38217_x;id' </dev/null
  [ "$status" -eq 1 ]
  [ "${lines[-1]}" = '{"ok":false,"script":"db-query","error":"invalid_database_name"}' ]

  local rows
  for rows in 0 1001 -1 10a ''; do
    run bash "${BATS_TEST_TMPDIR}/db-query" --name ow38217_shop --max-rows "$rows" </dev/null
    [ "$status" -eq 1 ]
    [[ "${lines[-1]}" == *'"error":"invalid_arguments"'* ]] || { echo "accepted: $rows"; return 1; }
  done

  run bash "${BATS_TEST_TMPDIR}/db-query" --name ow38217_shop --sql 'SELECT 1' </dev/null
  [ "$status" -eq 1 ]
  [[ "${lines[-1]}" == *'"error":"invalid_arguments"'* ]]
}

# The helper runs without MariaDB up to the sign-in, so its input checks
# can be tested here.
@test "the query helper needs a password line and SQL" {
  local helper="${ROOT}/server/lib/db-query.php"
  command -v php >/dev/null || skip "PHP is not installed"

  run bash -c "printf 'short\nSELECT 1' | php '${helper}' --name ow38217_shop"
  [ "$status" -eq 1 ]
  [ "${lines[-1]}" = '{"ok":false,"script":"db-query","error":"invalid_password"}' ]

  run bash -c "printf '%s\n  \n' \"\$(printf 'a%.0s' {1..40})\" | php '${helper}' --name ow38217_shop"
  [ "$status" -eq 1 ]
  [ "${lines[-1]}" = '{"ok":false,"script":"db-query","error":"empty_query"}' ]

  run bash -c "printf 'x' | php '${helper}' --name 'ow38217_shop;x'"
  [ "$status" -eq 1 ]
  [ "${lines[-1]}" = '{"ok":false,"script":"db-query","error":"invalid_database_name"}' ]
}

@test "db-export and db-import check name and password before anything else" {
  local script
  for script in db-export db-import; do
    run bash "${BATS_TEST_TMPDIR}/${script}" --name 'ow38217_x;id' </dev/null
    [ "$status" -eq 1 ]
    [ "${lines[-1]}" = "{\"ok\":false,\"script\":\"${script}\",\"error\":\"invalid_database_name\"}" ]

    run bash -c "printf 'short\n' | bash '${BATS_TEST_TMPDIR}/${script}' --name ow38217_shop"
    [ "$status" -eq 1 ]
    [ "${lines[-1]}" = "{\"ok\":false,\"script\":\"${script}\",\"error\":\"invalid_password\"}" ]

    run bash "${BATS_TEST_TMPDIR}/${script}" --name ow38217_shop --what </dev/null
    [ "$status" -eq 1 ]
    [[ "${lines[-1]}" == *'"error":"invalid_arguments"'* ]]
  done
}

@test "db-export keeps stdout free for the dump" {
  local out err
  out="${BATS_TEST_TMPDIR}/out"
  err="${BATS_TEST_TMPDIR}/err"
  bash "${BATS_TEST_TMPDIR}/db-export" --name 'ow38217_x;id' </dev/null >"$out" 2>"$err" || true
  [ ! -s "$out" ]
  grep -q '"error":"invalid_database_name"' "$err"
}

@test "db-import removes DEFINER clauses" {
  run bash -c "source <(sed -n '/^plain_sql()/,/^}/p' '${ROOT}/server/bin/db-import'); printf '%s\n' 'CREATE DEFINER=\`root\`@\`localhost\` VIEW v AS SELECT 1;' '/*!50013 DEFINER=\`x\`@\`%\` SQL SECURITY DEFINER */' | plain_sql 0"
  [ "$status" -eq 0 ]
  [ "${lines[0]}" = 'CREATE  VIEW v AS SELECT 1;' ]
  [ "${lines[1]}" = '/*!50013  SQL SECURITY DEFINER */' ]
}
