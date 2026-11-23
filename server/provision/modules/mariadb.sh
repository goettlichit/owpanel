# shellcheck shell=bash
# shellcheck disable=SC2154  # TEMPLATES comes from server-provision

mariadb_config_check() {
  mariadbd --user=mysql --help --verbose >/dev/null
}

# root logs in through the unix socket, Debian's default. No password is set.
provision_mariadb() {
  owp_apt_install mariadb-server
  systemctl enable --now mariadb

  owp_apply_config "${TEMPLATES}/mariadb/60-owpanel.cnf" \
    /etc/mysql/mariadb.conf.d/60-owpanel.cnf 0644 mariadb restart \
    -- mariadb_config_check || owp_die "mariadb_config_invalid" "MariaDB rejected 60-owpanel.cnf"

  mariadb --protocol=socket --execute="
    DELETE FROM mysql.global_priv WHERE User = '';
    DROP DATABASE IF EXISTS test;
    FLUSH PRIVILEGES;"
}
