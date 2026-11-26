#!/usr/bin/env bash
# Runs ShellCheck and the bats tests for the server scripts. Needs no root and
# changes nothing on the system. GitHub Actions runs the same script.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

scripts=(
  install
  get-owpanel
  scripts/build-release
  scripts/release-notes
  server/provision/setup-agent
  server/bin/*
  server/lib/*.sh
  server/provision/modules/*.sh
  server/templates/certbot/owpanel-reload-nginx
  tests/check.sh
  tests/vm/acceptance.sh
)

echo "== ShellCheck"
shellcheck -x --source-path=SCRIPTDIR/../.. "${scripts[@]}"

echo "== bats"
bats tests/
