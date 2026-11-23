#!/usr/bin/env bats
# Tests for server/bin/site-certificates with self-signed certificates.

ROOT="${BATS_TEST_DIRNAME}/.."

setup() {
  SCRIPT="${BATS_TEST_TMPDIR}/site-certificates"
  sed "s#/opt/owpanel/lib/#${ROOT}/server/lib/#" "${ROOT}/server/bin/site-certificates" >"$SCRIPT"
  export OWP_CERT_LIVE="${BATS_TEST_TMPDIR}/live"
  mkdir -p "$OWP_CERT_LIVE"
}

# certificate <name> <days> <domain>…
certificate() {
  local name="$1" days="$2" san
  shift 2
  san="$(printf 'DNS:%s,' "$@")"
  mkdir -p "${OWP_CERT_LIVE}/${name}"
  openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes -days "$days" \
    -subj "/CN=$1" -addext "subjectAltName=${san%,}" \
    -keyout "${OWP_CERT_LIVE}/${name}/privkey.pem" -out "${OWP_CERT_LIVE}/${name}/cert.pem" 2>/dev/null
}

json() {
  printf '%s' "${lines[-1]}" | python3 -c "import json,sys; d=json.load(sys.stdin); print($1)"
}

@test "site-certificates lists site and panel certificates with expiry and domains" {
  certificate ow10000 90 panel.example.de
  certificate ow38217 30 shop.example.de
  run bash "$SCRIPT"
  [ "$status" -eq 0 ]
  [ "$(json '[c["user"] for c in d["certificates"]]')" = "['ow10000', 'ow38217']" ]
  [ "$(json 'd["certificates"][1]["domains"]')" = "['shop.example.de']" ]
  local expected
  expected="$(date -d '+30 days' +%s)"
  [ "$(json "abs(d['certificates'][1]['expires'] - ${expected}) < 120")" = "True" ]
}

@test "site-certificates skips foreign names and odd domains" {
  certificate example.de 30 example.de
  certificate ow9999 30 old.example.de
  certificate ow12345 30 'Bad_Domain.example.de' good.example.de
  run bash "$SCRIPT"
  [ "$status" -eq 0 ]
  [ "$(json '[c["user"] for c in d["certificates"]]')" = "['ow12345']" ]
  [ "$(json 'd["certificates"][0]["domains"]')" = "['good.example.de']" ]
}

@test "site-certificates works without certificates" {
  run bash "$SCRIPT"
  [ "$status" -eq 0 ]
  [ "$(json 'd["certificates"]')" = "[]" ]
}

@test "site-certificates and server-reboot accept no parameters" {
  run bash "$SCRIPT" --all
  [ "$status" -eq 1 ]
  [ "${lines[-1]}" = '{"ok":false,"script":"site-certificates","error":"invalid_arguments"}' ]

  sed "s#/opt/owpanel/lib/#${ROOT}/server/lib/#" "${ROOT}/server/bin/server-reboot" >"${BATS_TEST_TMPDIR}/server-reboot"
  run bash "${BATS_TEST_TMPDIR}/server-reboot" --now
  [ "$status" -eq 1 ]
  [ "${lines[-1]}" = '{"ok":false,"script":"server-reboot","error":"invalid_arguments"}' ]
}
