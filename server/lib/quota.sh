# shellcheck shell=bash
# Linux user quotas for the files of the websites (E-126). The base setup
# turns on journaled user quotas for the file system with /var/www on ext4;
# site-usage measures, site-quota sets the hard limit of one website user,
# site-restore lifts it while it copies files back.

OWP_QUOTA_ROOT="${OWP_QUOTA_ROOT:-/var/www}"

# Mount point of the file system that holds the websites.
owp_quota_mountpoint() {
  findmnt -n -o TARGET --target "$OWP_QUOTA_ROOT"
}

# 0 when user quotas are on for that file system. quotaon -p exits with 1
# when quotas are ON, so only its output counts; with pipefail a pipe into
# grep would always fail exactly then.
owp_quota_active() {
  command -v quotaon >/dev/null 2>&1 || return 1
  local state
  state="$(quotaon -pu "$(owp_quota_mountpoint)" 2>/dev/null || true)"
  [[ "$state" == *" is on"* ]]
}

# Prints "<user> <used KiB> <hard limit KiB>" for every website user, from
# the quota files. In the default report the block columns always come
# first: used, soft, hard; a grace time only follows them.
owp_quota_report() {
  repquota -u "$(owp_quota_mountpoint)" 2>/dev/null \
    | awk '$1 ~ /^ow[0-9]{5}$/ && $3 ~ /^[0-9]+$/ && $5 ~ /^[0-9]+$/ { print $1, $3, $5 }'
}

# Hard limit of one user in KiB, 0 for none.
owp_quota_hard_kib() {
  owp_quota_report | awk -v u="$1" '$1 == u { print $3; found = 1 } END { if (!found) print 0 }'
}

# Sets the hard limit of one user in KiB; 0 removes it. No soft limit: the
# panel warns from 90 %.
owp_quota_set_kib() {
  setquota -u "$1" 0 "$2" 0 0 "$(owp_quota_mountpoint)"
}

# Lifts the limit of a user until the script ends; owp_on_exit sets it
# back. For restores, which must not stop halfway (E-126).
owp_quota_lift() {
  local user="$1" hard
  owp_quota_active || return 0
  hard="$(owp_quota_hard_kib "$user")"
  [[ "$hard" =~ ^[0-9]+$ ]] || return 0
  ((hard > 0)) || return 0
  OWP_QUOTA_RESTORE="${user}:${hard}"
  owp_quota_set_kib "$user" 0 || owp_warn "the quota of ${user} could not be lifted"
}

owp_quota_restore() {
  [[ -n "${OWP_QUOTA_RESTORE:-}" ]] || return 0
  owp_quota_set_kib "${OWP_QUOTA_RESTORE%%:*}" "${OWP_QUOTA_RESTORE##*:}" \
    || owp_warn "the quota of ${OWP_QUOTA_RESTORE%%:*} could not be set back"
  OWP_QUOTA_RESTORE=""
}
