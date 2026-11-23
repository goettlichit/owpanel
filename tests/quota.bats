#!/usr/bin/env bats
# Argument checks of the storage scripts (E-126). Measuring and setting
# quotas needs root and a real file system; that is tested on a VM.

ROOT="${BATS_TEST_DIRNAME}/.."

setup() {
  for script in site-usage site-quota; do
    sed "s#/opt/owpanel/lib/#${ROOT}/server/lib/#" "${ROOT}/server/bin/${script}" >"${BATS_TEST_TMPDIR}/${script}"
  done
}

@test "site-usage takes no parameters" {
  run bash "${BATS_TEST_TMPDIR}/site-usage" --user ow38217
  [[ "${lines[-1]}" == *'"error":"invalid_arguments"'* ]]
}

@test "site-quota checks user and size before anything else" {
  run bash "${BATS_TEST_TMPDIR}/site-quota" --user root --kib 1024
  [[ "${lines[-1]}" == *'"error":"invalid_user"'* ]]
  run bash "${BATS_TEST_TMPDIR}/site-quota" --user ow10000 --kib 1024
  [[ "${lines[-1]}" == *'"error":"reserved_user"'* ]]
  for kib in "" -1 01 abc 1.5 99999999999999; do
    run bash "${BATS_TEST_TMPDIR}/site-quota" --user ow38217 --kib "$kib"
    [[ "${lines[-1]}" == *'"error":"invalid_arguments"'* ]]
  done
}

@test "the quota report reads used and hard limit of the website users" {
  repquota() {
    printf '*** Report for user quotas on device /dev/sda1\n'
    printf 'Block grace time: 7days; Inode grace time: 7days\n'
    printf '                        Block limits                File limits\n'
    printf 'User            used    soft    hard  grace    used  soft  hard  grace\n'
    printf -- '----------------------------------------------------------------------\n'
    printf 'root      --  1000       0       0             10     0     0\n'
    printf 'ow38217   +-  5120       0    5120  none        5     0     0\n'
    printf 'ow40000   --    10       0       0              1     0     0\n'
  }
  findmnt() { echo /; }
  source "${ROOT}/server/lib/common.sh"
  source "${ROOT}/server/lib/quota.sh"
  run owp_quota_report
  [ "${lines[0]}" = "ow38217 5120 5120" ]
  [ "${lines[1]}" = "ow40000 10 0" ]
  [ "${#lines[@]}" -eq 2 ]
  run owp_quota_hard_kib ow38217
  [ "$output" = "5120" ]
  run owp_quota_hard_kib ow49999
  [ "$output" = "0" ]
}

@test "quotas count as on although quotaon -p exits with 1 then" {
  findmnt() { echo /; }
  source "${ROOT}/server/lib/common.sh"
  source "${ROOT}/server/lib/quota.sh"
  set -o pipefail
  quotaon() { echo "user quota on / (/dev/sda1) is on"; return 1; }
  run owp_quota_active
  [ "$status" -eq 0 ]
  quotaon() { echo "user quota on / (/dev/sda1) is off"; return 0; }
  run owp_quota_active
  [ "$status" -ne 0 ]
}

@test "the base setup creates /var/www before it looks at its file system" {
  run bash -c '
    set -euo pipefail
    source "'"${ROOT}"'/server/lib/common.sh"
    export OWP_QUOTA_ROOT="'"${BATS_TEST_TMPDIR}"'/www"
    findmnt() { [[ -e "${@: -1}" ]] || return 1; case "$*" in *FSTYPE*) echo tmpfs ;; *) echo / ;; esac; }
    source <(sed "s#\${OWP_ROOT}/lib/#'"${ROOT}"'/server/lib/#" "'"${ROOT}"'/server/provision/modules/quota.sh")
    provision_quota
  '
  [ "$status" -eq 0 ]
  [ -d "${BATS_TEST_TMPDIR}/www" ]
  [[ "$output" == *"need ext4"* ]]
}
