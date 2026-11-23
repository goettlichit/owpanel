#!/usr/bin/env bats
# Tests for server/bin/server-updates. Runs without root and reads fixtures
# instead of apt, so the result is known.

ROOT="${BATS_TEST_DIRNAME}/.."

setup() {
  SCRIPT="${BATS_TEST_TMPDIR}/server-updates"
  sed "s#/opt/owpanel/lib/#${ROOT}/server/lib/#" "${ROOT}/server/bin/server-updates" >"$SCRIPT"

  cat >"${BATS_TEST_TMPDIR}/simulation" <<'SIM'
NOTE: This is only a simulation!
Reading package lists...
Inst libssl3t64 [3.5.1-1] (3.5.4-1~deb13u1 Debian-Security:13/stable-security [amd64])
Inst nginx [1.29.2-1~trixie] (1.29.3-1~trixie nginx.org:13/stable [amd64])
Inst linux-image-6.12.57+deb13-amd64 (6.12.57-1 Debian:13.2/stable [amd64])
Inst bad;name [1] (2 Debian:13/stable [amd64])
Inst evil [1] (2"x Debian:13/stable [amd64])
Conf libssl3t64 (3.5.4-1~deb13u1 Debian-Security:13/stable-security [amd64])
SIM
  cat >"${BATS_TEST_TMPDIR}/history.log" <<'HIST'

Start-Date: 2026-11-09  06:12:01
Commandline: /usr/bin/unattended-upgrade
Upgrade: openssl:amd64 (3.5.1-1, 3.5.4-1~deb13u1), libssl3t64:amd64 (3.5.1-1, 3.5.4-1~deb13u1)
End-Date: 2026-11-09  06:12:20

Start-Date: 2026-11-10  09:00:00
Commandline: apt-get install htop
Requested-By: admin (1000)
Install: htop:amd64 (3.4.1-5)
End-Date: 2026-11-10  09:00:03

Start-Date: 2026-11-10  10:00:00
Commandline: apt-get remove htop
Remove: htop:amd64 (3.4.1-5)
End-Date: 2026-11-10  10:00:01
HIST
  mkdir -p "${BATS_TEST_TMPDIR}/stamps" "${BATS_TEST_TMPDIR}/modules/$(uname -r)"
  touch -d '2026-11-10 06:00:00' "${BATS_TEST_TMPDIR}/stamps/update-success-stamp"

  export OWP_UPDATES_SIMULATION="${BATS_TEST_TMPDIR}/simulation"
  export OWP_UPDATES_HISTORY="${BATS_TEST_TMPDIR}/history.log"
  export OWP_UPDATES_STAMPS="${BATS_TEST_TMPDIR}/stamps"
  export OWP_APT_LISTS="${BATS_TEST_TMPDIR}/lists"
  mkdir -p "${BATS_TEST_TMPDIR}/lists"
  export OWP_STATUS_REBOOT_FILE="${BATS_TEST_TMPDIR}/reboot-required"
  export OWP_MODULES_DIR="${BATS_TEST_TMPDIR}/modules"
}

json() {
  printf '%s' "${lines[-1]}" | python3 -c "import json,sys; d=json.load(sys.stdin); print($1)"
}

@test "server-updates counts pending and security updates" {
  run bash "$SCRIPT"
  [ "$status" -eq 0 ]
  [ "$(json 'd["ok"], d["pending"], d["security"]')" = "True 3 1" ]
  [ "$(json '[p["name"] for p in d["packages"]]')" = "['libssl3t64', 'nginx', 'linux-image-6.12.57+deb13-amd64']" ]
  [ "$(json 'd["packages"][0]')" = "{'name': 'libssl3t64', 'from': '3.5.1-1', 'to': '3.5.4-1~deb13u1', 'security': True}" ]
  [ "$(json 'd["packages"][2]["from"]')" = "" ]
}

@test "server-updates lists runs that installed packages, newest first" {
  run bash "$SCRIPT"
  [ "$status" -eq 0 ]
  [ "$(json 'len(d["runs"])')" = "2" ]
  [ "$(json 'd["runs"][0]["kind"], d["runs"][0]["count"], d["runs"][0]["packages"]')" = "manual 1 ['htop']" ]
  [ "$(json 'd["runs"][1]["kind"], d["runs"][1]["count"], d["runs"][1]["packages"]')" = "automatic 2 ['openssl', 'libssl3t64']" ]
  [ "$(json 'd["runs"][1]["at"]')" = "$(date -d '2026-11-09 06:12:01' +%s)" ]
}

@test "server-updates reports stamps and missing stamps" {
  run bash "$SCRIPT"
  [ "$status" -eq 0 ]
  [ "$(json 'd["lists_updated"]')" = "$(date -d '2026-11-10 06:00:00' +%s)" ]
  [ "$(json 'd["upgraded"]')" = "None" ]
}

@test "server-updates takes the newest Release file when the stamp is missing or older" {
  rm "${BATS_TEST_TMPDIR}/stamps/update-success-stamp"
  run bash "$SCRIPT"
  [ "$(json 'd["lists_updated"]')" = "None" ]

  touch -d '2026-11-11 05:30:00' "${BATS_TEST_TMPDIR}/lists/deb.debian.org_debian_dists_trixie_InRelease"
  touch -d '2026-11-11 05:31:00' "${BATS_TEST_TMPDIR}/lists/security.debian.org_dists_trixie-security_InRelease"
  touch -d '2026-11-11 07:00:00' "${BATS_TEST_TMPDIR}/lists/lock"
  run bash "$SCRIPT"
  [ "$status" -eq 0 ]
  [ "$(json 'd["lists_updated"]')" = "$(date -d '2026-11-11 05:31:00' +%s)" ]

  touch -d '2026-11-10 06:00:00' "${BATS_TEST_TMPDIR}/stamps/update-success-stamp"
  run bash "$SCRIPT"
  [ "$(json 'd["lists_updated"]')" = "$(date -d '2026-11-11 05:31:00' +%s)" ]
}

@test "server-updates reports no reboot for the running kernel" {
  run bash "$SCRIPT"
  [ "$status" -eq 0 ]
  [ "$(json 'd["reboot"]["required"], d["reboot"]["kernel_installed"]')" = "False " ]
  [ "$(json 'd["reboot"]["kernel_running"]')" = "$(uname -r)" ]
}

@test "server-updates reports a newer kernel and the packages asking for a reboot" {
  mkdir -p "${OWP_MODULES_DIR}/999.1.0-1-amd64"
  printf 'linux-image-amd64\nlibc6\nlibc6\n$(id)\n' >"${OWP_STATUS_REBOOT_FILE}.pkgs"
  touch "$OWP_STATUS_REBOOT_FILE"
  run bash "$SCRIPT"
  [ "$status" -eq 0 ]
  [ "$(json 'd["reboot"]["required"], d["reboot"]["kernel_installed"]')" = "True 999.1.0-1-amd64" ]
  [ "$(json 'd["reboot"]["packages"]')" = "['linux-image-amd64', 'libc6']" ]
}

@test "server-updates works without any history and pending updates" {
  : >"$OWP_UPDATES_SIMULATION"
  rm "$OWP_UPDATES_HISTORY"
  run bash "$SCRIPT"
  [ "$status" -eq 0 ]
  [ "$(json 'd["pending"], d["security"], d["packages"], d["runs"]')" = "0 0 [] []" ]
}

@test "server-updates accepts no parameters" {
  run bash "$SCRIPT" --all
  [ "$status" -eq 1 ]
  [ "${lines[-1]}" = '{"ok":false,"script":"server-updates","error":"invalid_arguments"}' ]
}
