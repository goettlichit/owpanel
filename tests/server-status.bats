#!/usr/bin/env bats
# Tests for server/bin/server-status. Runs without root and reads fixtures
# instead of /proc, so the numbers are known.

ROOT="${BATS_TEST_DIRNAME}/.."

setup() {
  SCRIPT="${BATS_TEST_TMPDIR}/server-status"
  sed "s#/opt/owpanel/lib/#${ROOT}/server/lib/#" "${ROOT}/server/bin/server-status" >"$SCRIPT"

  PROC="${BATS_TEST_TMPDIR}/proc"
  mkdir -p "${PROC}/net"
  # Both reads of /proc/stat see the same numbers, so CPU usage is 0.
  printf 'cpu  1000 0 500 8000 100 0 0 0 0 0\n' >"${PROC}/stat"
  printf 'processor\t: 0\nprocessor\t: 1\n' >"${PROC}/cpuinfo"
  printf '0.52 0.40 0.31 1/123 4567\n' >"${PROC}/loadavg"
  printf '12345.67 23456.78\n' >"${PROC}/uptime"
  cat >"${PROC}/meminfo" <<'MEM'
MemTotal:        4000000 kB
MemFree:          500000 kB
MemAvailable:    3000000 kB
SwapTotal:       1000000 kB
SwapFree:         750000 kB
MEM
  cat >"${PROC}/net/dev" <<'NET'
Inter-|   Receive                                                |  Transmit
 face |bytes    packets errs drop fifo frame compressed multicast|bytes    packets errs drop fifo colls carrier compressed
    lo: 999999     10    0    0    0     0          0         0   999999      10    0    0    0     0       0          0
  eth0: 1000       5    0    0    0     0          0         0     2000       5    0    0    0     0       0          0
  eth1:  500       1    0    0    0     0          0         0      700       1    0    0    0     0       0          0
NET
  printf 'Iface\tDestination\tGateway\tFlags\tRefCnt\tUse\tMetric\tMask\n' >"${PROC}/net/route"
  printf 'eth0\t00000000\t0101010A\t0003\t0\t0\t0\t00000000\n' >>"${PROC}/net/route"
  printf 'eth1\t0000000A\t00000000\t0001\t0\t0\t0\t0000FFFF\n' >>"${PROC}/net/route"
  # eth0 and eth1 are network cards, lo is not.
  SYS="${BATS_TEST_TMPDIR}/sys"
  mkdir -p "${SYS}/class/net/eth0/device" "${SYS}/class/net/eth1/device" "${SYS}/class/net/lo"
  export OWP_STATUS_SYS="$SYS"
  printf 'NAME="Debian GNU/Linux"\nPRETTY_NAME="Debian GNU/Linux 13 (trixie)"\n' >"${BATS_TEST_TMPDIR}/os-release"

  export OWP_STATUS_PROC="$PROC"
  export OWP_STATUS_OS_RELEASE="${BATS_TEST_TMPDIR}/os-release"
  export OWP_STATUS_REBOOT_FILE="${BATS_TEST_TMPDIR}/reboot-required"
  export OWP_STATUS_INTERVAL=0
  # Only the running kernel is installed: no reboot needed.
  export OWP_MODULES_DIR="${BATS_TEST_TMPDIR}/modules"
  mkdir -p "${OWP_MODULES_DIR}/$(uname -r)"
  export OWP_SITES_ROOT="${BATS_TEST_TMPDIR}/www"
  mkdir -p "$OWP_SITES_ROOT"
  # systemctl stub: enabled and running units come from the variables.
  mkdir -p "${BATS_TEST_TMPDIR}/bin"
  cat >"${BATS_TEST_TMPDIR}/bin/systemctl" <<'STUB'
#!/bin/bash
case "$1" in
  list-unit-files) for unit in $STUB_FPM; do printf '%s enabled enabled\n' "$unit"; done ;;
  is-enabled) [[ " $STUB_ENABLED " == *" ${@: -1} "* ]] ;;
  is-active) [[ " $STUB_ACTIVE " == *" ${@: -1} "* ]] ;;
  *) exit 1 ;;
esac
STUB
  chmod +x "${BATS_TEST_TMPDIR}/bin/systemctl"
  export OWP_SYSTEMCTL="${BATS_TEST_TMPDIR}/bin/systemctl"
  export STUB_FPM="" STUB_ENABLED="" STUB_ACTIVE=""
  export OWP_STATUS_VERSION_FILE="${BATS_TEST_TMPDIR}/VERSION"
  export OWP_STATUS_SIGNERS_FILE="${BATS_TEST_TMPDIR}/release-signers"
  export OWP_STATUS_PHP_FPM="${BATS_TEST_TMPDIR}/php-fpm"
}

json() {
  printf '%s' "${lines[-1]}" | python3 -c "import json,sys; d=json.load(sys.stdin); print($1)"
}

@test "server-status reports load, memory, swap, network and system" {
  run bash "$SCRIPT"
  [ "$status" -eq 0 ]
  [ "$(json 'd["ok"], d["script"]')" = "True server-status" ]
  [ "$(json 'd["cpu_cores"]')" = "2" ]
  [ "$(json 'd["load"]')" = "[0.52, 0.4, 0.31]" ]
  [ "$(json 'd["uptime"]')" = "12345" ]
  [ "$(json 'd["memory"]["total"], d["memory"]["used"]')" = "4096000000 1024000000" ]
  [ "$(json 'd["swap"]["total"], d["swap"]["used"]')" = "1024000000 256000000" ]
  [ "$(json 'd["network"]["rx"], d["network"]["tx"]')" = "1500 2700" ]
  [ "$(json '[(i["name"], i["rx"], i["tx"], i["public"]) for i in d["network"]["interfaces"]]')" = "[('eth0', 1000, 2000, True), ('eth1', 500, 700, False)]" ]
  [ "$(json 'd["os"]')" = "Debian GNU/Linux 13 (trixie)" ]
  [ "$(json 'd["reboot_required"]')" = "False" ]
  [ "$(json 'd["disk"]["total"] > 0 and d["disk"]["used"] >= 0')" = "True" ]
  [ "$(json 'd["cpu"]')" = "0.0" ]
}

@test "server-status reports a pending reboot" {
  touch "$OWP_STATUS_REBOOT_FILE"
  run bash "$SCRIPT"
  [ "$status" -eq 0 ]
  [ "$(json 'd["reboot_required"]')" = "True" ]
}

@test "server-status reports a reboot for a newer installed kernel" {
  mkdir -p "${OWP_MODULES_DIR}/999.0.0-1-amd64"
  run bash "$SCRIPT"
  [ "$status" -eq 0 ]
  [ "$(json 'd["reboot_required"]')" = "True" ]
}

@test "server-status ignores older kernels" {
  mkdir -p "${OWP_MODULES_DIR}/1.0.0-1-amd64"
  run bash "$SCRIPT"
  [ "$status" -eq 0 ]
  [ "$(json 'd["reboot_required"]')" = "False" ]
}

@test "server-status drops an os name with unexpected characters" {
  printf 'PRETTY_NAME="Evil\\",\\"x\\":\\"1"\n' >"$OWP_STATUS_OS_RELEASE"
  run bash "$SCRIPT"
  [ "$status" -eq 0 ]
  [ "$(json 'd["os"]')" = "" ]
}

@test "server-status reports a fully qualified host name or a valid short one" {
  run bash "$SCRIPT"
  [ "$status" -eq 0 ]
  [[ "$(json 'd["hostname"]')" =~ ^[a-z0-9.-]*$ ]]
}

@test "server-status fails cleanly on unreadable input" {
  printf 'MemTotal: 1 kB\n' >"${PROC}/meminfo"
  run bash "$SCRIPT"
  [ "$status" -eq 1 ]
  [ "${lines[-1]}" = '{"ok":false,"script":"server-status","error":"status_unreadable"}' ]
}

@test "server-status accepts no parameters" {
  run bash "$SCRIPT" --verbose
  [ "$status" -eq 1 ]
  [ "${lines[-1]}" = '{"ok":false,"script":"server-status","error":"invalid_arguments"}' ]
}

@test "server-status lists the websites that exist, without the panel" {
  mkdir -p "${OWP_SITES_ROOT}/ow38217/htdocs" "${OWP_SITES_ROOT}/ow10000/htdocs" \
    "${OWP_SITES_ROOT}/ow49545" "${OWP_SITES_ROOT}/owner/htdocs" "${OWP_SITES_ROOT}/elsewhere"
  ln -s "${OWP_SITES_ROOT}/elsewhere" "${OWP_SITES_ROOT}/ow26069"
  run bash "$SCRIPT"
  [ "$status" -eq 0 ]
  [ "$(json 'd["sites"]')" = "['ow38217']" ]
}

@test "server-status reports an empty website list" {
  run bash "$SCRIPT"
  [ "$status" -eq 0 ]
  [ "$(json 'd["sites"]')" = "[]" ]
}

@test "server-status finds the public interface by its IPv6 default route" {
  printf 'Iface\tDestination\tGateway\tFlags\tRefCnt\tUse\tMetric\tMask\n' >"${PROC}/net/route"
  printf '%s 00 %s 00 %s 00000400 00000001 00000000 00000003 eth1\n' \
    00000000000000000000000000000000 00000000000000000000000000000000 fe800000000000000000000000000001 >"${PROC}/net/ipv6_route"
  run bash "$SCRIPT"
  [ "$status" -eq 0 ]
  [ "$(json '[i["name"] for i in d["network"]["interfaces"] if i["public"]]')" = "['eth1']" ]
}

@test "server-status leaves out virtual interfaces and odd names" {
  cat >>"${PROC}/net/dev" <<'NET'
  wg0:   10       1    0    0    0     0          0         0       20       1    0    0    0     0       0          0
NET
  run bash "$SCRIPT"
  [ "$status" -eq 0 ]
  [ "$(json '[i["name"] for i in d["network"]["interfaces"]]')" = "['eth0', 'eth1']" ]
  [ "$(json 'd["network"]["rx"]')" = "1510" ]
}

@test "server-status reports enabled services and whether they run" {
  export STUB_FPM="php8.4-fpm.service php8.3-fpm.service"
  export STUB_ENABLED="nginx.service fail2ban.service nftables.service php8.4-fpm.service php8.3-fpm.service"
  export STUB_ACTIVE="nginx.service nftables.service php8.4-fpm.service"
  run bash "$SCRIPT"
  [ "$status" -eq 0 ]
  [ "$(json '{s["name"]: s["active"] for s in d["services"]}')" = "{'fail2ban': False, 'nftables': True, 'nginx': True, 'php8.3-fpm': False, 'php8.4-fpm': True}" ]
}

@test "server-status reports no services without enabled units" {
  run bash "$SCRIPT"
  [ "$status" -eq 0 ]
  [ "$(json 'd["services"]')" = "[]" ]
}

@test "server-status reports the version of the server scripts (E-167)" {
  printf '1.2.3\n' >"$OWP_STATUS_VERSION_FILE"
  run bash "$SCRIPT"
  [ "$status" -eq 0 ]
  [ "$(json 'd["scripts_version"]')" = "1.2.3" ]

  printf 'dev-0123456789ab\n' >"$OWP_STATUS_VERSION_FILE"
  run bash "$SCRIPT"
  [ "$(json 'd["scripts_version"]')" = "dev-0123456789ab" ]
}

@test "server-status reports null without or with an odd version file" {
  run bash "$SCRIPT"
  [ "$status" -eq 0 ]
  [ "$(json 'd["scripts_version"]')" = "None" ]

  printf '1.2.3"},"x":"y\n' >"$OWP_STATUS_VERSION_FILE"
  run bash "$SCRIPT"
  [ "$status" -eq 0 ]
  [ "$(json 'd["scripts_version"]')" = "None" ]
}

@test "server-status reports the fingerprints of the release keys (E-167)" {
  command -v ssh-keygen >/dev/null || skip "ssh-keygen is missing"
  run bash "$SCRIPT"
  [ "$(json 'd["release_keys"]')" = "[]" ]

  ssh-keygen -q -t ed25519 -N '' -f "${BATS_TEST_TMPDIR}/key"
  {
    printf '# comment\n\n'
    printf 'owpanel-release namespaces="owpanel-release" %s\n' "$(cut -d' ' -f1,2 "${BATS_TEST_TMPDIR}/key.pub")"
    printf 'someone-else namespaces="x" %s\n' "$(cut -d' ' -f1,2 "${BATS_TEST_TMPDIR}/key.pub")"
    printf 'owpanel-release ssh-ed25519 not"base64\n'
  } >"$OWP_STATUS_SIGNERS_FILE"
  run bash "$SCRIPT"
  [ "$status" -eq 0 ]
  expected="$(ssh-keygen -l -E sha256 -f "${BATS_TEST_TMPDIR}/key.pub" | awk '{ print $2 }')"
  [ "$(json 'd["release_keys"]')" = "['${expected}']" ]
}

@test "server-status lists the PHP versions installed for websites (E-172)" {
  run bash "$SCRIPT"
  [ "$(json 'd["php_versions"]')" = "[]" ]
  for v in 8.5 8.6 8.4; do
    printf '#!/bin/sh\n' >"${OWP_STATUS_PHP_FPM}${v}"
    chmod +x "${OWP_STATUS_PHP_FPM}${v}"
  done
  export STUB_ACTIVE="php8.5-fpm.service php8.6-fpm.service php8.4-fpm.service"
  run bash "$SCRIPT"
  [ "$status" -eq 0 ]
  # 8.4 is installed but not offered.
  [ "$(json 'd["php_versions"]')" = "['8.5', '8.6']" ]
  # A version whose PHP-FPM does not run is not offered either.
  export STUB_ACTIVE="php8.5-fpm.service"
  run bash "$SCRIPT"
  [ "$(json 'd["php_versions"]')" = "['8.5']" ]
}
