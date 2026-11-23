#!/usr/bin/env bats
# Tests for server/bin/panel-test. Runs without root and changes nothing.

ROOT="${BATS_TEST_DIRNAME}/.."

setup() {
  # The installed script reads its library from /opt/owpanel. The copy reads
  # it from the repository instead.
  SCRIPT="${BATS_TEST_TMPDIR}/panel-test"
  sed "s#/opt/owpanel/lib/#${ROOT}/server/lib/#" "${ROOT}/server/bin/panel-test" >"$SCRIPT"
}

@test "panel-test runs the requested steps and reports the user" {
  run bash "$SCRIPT" --steps 2
  [ "$status" -eq 0 ]
  [[ "$output" == *"step 1 of 2"* ]]
  [[ "$output" == *"step 2 of 2"* ]]
  [[ "$output" == *"test message on stderr"* ]]
  [ "${lines[-1]}" = "{\"ok\":true,\"script\":\"panel-test\",\"steps\":2,\"user\":\"$(id -un)\"}" ]
}

@test "panel-test ends with test_failed on --fail" {
  run bash "$SCRIPT" --steps 1 --fail
  [ "$status" -eq 1 ]
  [ "${lines[-1]}" = '{"ok":false,"script":"panel-test","error":"test_failed"}' ]
}

@test "panel-test rejects manipulated step counts" {
  local steps
  for steps in 0 11 -1 01 1.5 "" " 1" "1 " "1;id" '$(id)' abc $'1\n2'; do
    run bash "$SCRIPT" --steps "$steps"
    [ "$status" -eq 1 ] || { echo "accepted: $steps"; return 1; }
    [ "${lines[-1]}" = '{"ok":false,"script":"panel-test","error":"invalid_steps"}' ]
  done
}

@test "panel-test rejects unknown and incomplete parameters" {
  local args
  for args in "--bogus" "--steps" "positional" "--fail=1"; do
    # shellcheck disable=SC2086  # each entry is a parameter list
    run bash "$SCRIPT" $args
    [ "$status" -eq 1 ]
    [[ "${lines[-1]}" == *'"error":"invalid_arguments"'* ]]
  done
}

@test "sudo rules allow exactly the scripts in server/bin" {
  local expected actual
  expected="$(cd "${ROOT}/server/bin" && printf '/opt/owpanel/bin/%s\n' * | sort)"
  actual="$(grep -o '/opt/owpanel/bin/[a-z0-9-]*' "${ROOT}/server/templates/sudoers/owpanel-agent" | sort)"
  [ "$expected" = "$actual" ]
}
