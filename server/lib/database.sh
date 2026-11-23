# shellcheck shell=bash
# Helpers for database scripts (E-078). Sourced after common.sh and site.sh,
# never executed.
#
# A database and its user share one name: the site user, an underscore and
# a short suffix, e.g. ow38217_shop. The prefix ties both to their website,
# so site-delete can find them, and nothing in the name points to customer
# or domain. The user exists for localhost and 127.0.0.1 and only has rights
# on its own database (docs/SICHERHEIT.md, "Website-Isolation").

readonly OWP_DB_HOSTS=(localhost 127.0.0.1)

# ow38217_shop: a valid site user other than ow10000, then 1 to 16 of
# [a-z0-9_], starting with a letter or digit. At most 24 characters, well
# within the limits of MariaDB (64 for databases, 80 for users).
owp_valid_db_name() {
  local name="$1" user
  [[ "$name" =~ ^(ow[1-5][0-9]{4})_[a-z0-9][a-z0-9_]{0,15}$ ]] || return 1
  # Kept apart: owp_valid_site_user overwrites BASH_REMATCH.
  user="${BASH_REMATCH[1]}"
  owp_valid_site_user "$user" && ! owp_reserved_site_user "$user"
}

# Site user of a valid database name: ow38217_shop -> ow38217.
owp_db_site_user() {
  printf '%s\n' "${1%%_*}"
}

# Passwords come from the panel on stdin, never as an argument. Only
# letters and digits, so they need no quoting inside SQL.
owp_valid_db_password() {
  [[ "$1" =~ ^[A-Za-z0-9]{32,64}$ ]]
}

# Runs SQL from stdin as MariaDB's root through the unix socket.
owp_mariadb() {
  mariadb --protocol=socket --batch --skip-column-names "$@"
}

owp_mariadb_running() {
  command -v mariadb >/dev/null 2>&1 && systemctl is-active --quiet mariadb
}

owp_require_mariadb() {
  if ! owp_mariadb_running; then
    owp_die "mariadb_unavailable" "MariaDB is not installed or not running"
  fi
}

# Names of all databases and users that belong to a site user, one per
# line, each checked against owp_valid_db_name.
owp_site_databases() {
  local user="$1" name
  {
    owp_mariadb --execute="SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE '${user}\\_%'"
    owp_mariadb --execute="SELECT DISTINCT User FROM mysql.global_priv WHERE User LIKE '${user}\\_%'"
  } | sort -u | while read -r name; do
    if owp_valid_db_name "$name" && [[ "$(owp_db_site_user "$name")" == "$user" ]]; then
      printf '%s\n' "$name"
    fi
  done
}

# Drops a database and its users. A second call changes nothing.
owp_drop_database() {
  local name="$1" host
  {
    # shellcheck disable=SC2016  # backticks quote the name in SQL
    printf 'DROP DATABASE IF EXISTS `%s`;\n' "$name"
    for host in "${OWP_DB_HOSTS[@]}"; do
      printf "DROP USER IF EXISTS '%s'@'%s';\n" "$name" "$host"
    done
  } | owp_mariadb
}
