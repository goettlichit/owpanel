# shellcheck shell=bash
# Own rules of a website for nginx (E-184): extra response headers and
# blocked paths, for administrators only. There is no free nginx text: a
# line like "access_log /etc/cron.d/x" would let whoever controls the panel
# write files as root. Each rule is checked against a strict pattern and
# written by this script in a fixed form.
#
# Both files always exist for a website, empty without rules, because the
# site configuration includes them (site-create creates them, site-nginx
# fills them, site-delete removes them). They do not end in .conf inside
# owpanel-sites, so nginx does not load them as sites of their own.

OWP_SITE_RULES_DIR="${OWP_SITE_RULES_DIR:-/etc/nginx/owpanel-site-rules}"
readonly OWP_RULES_MAX_HEADERS=20
readonly OWP_RULES_MAX_PATHS=50
readonly OWP_RULES_MAX_VALUE=2048
readonly OWP_RULES_HEAD="# Managed by OWPanel. Do not edit, changes are overwritten."

# Counts of the last owp_rules_render, for the result line.
OWP_RULES_HEADERS=0
OWP_RULES_PATHS=0

owp_rules_headers_file() {
  printf '%s/%s-headers.conf\n' "$OWP_SITE_RULES_DIR" "$1"
}

owp_rules_paths_file() {
  printf '%s/%s-paths.conf\n' "$OWP_SITE_RULES_DIR" "$1"
}

# A header name: letters, digits and dashes. Headers OWPanel sets itself
# and those that would break responses are refused.
owp_rules_valid_header_name() {
  local re='^[A-Za-z][A-Za-z0-9-]{0,63}$'
  [[ "$1" =~ $re ]] || return 1
  case "${1,,}" in
    alt-svc | x-content-type-options | referrer-policy | x-frame-options \
      | connection | content-length | content-type | transfer-encoding | location \
      | set-cookie | keep-alive | upgrade | host | server | date) return 1 ;;
  esac
}

# A header value: printable ASCII without ", \, $, { and } (nginx would read
# $ as a variable), no space at either end, at most OWP_RULES_MAX_VALUE.
owp_rules_valid_header_value() {
  # shellcheck disable=SC2016  # $ is a character to refuse here
  local LC_ALL=C value="$1" printable='^[ -~]+$' bad='["\\${}]'
  ((${#value} <= OWP_RULES_MAX_VALUE)) || return 1
  [[ "$value" =~ $printable && ! "$value" =~ $bad && "$value" != " "* && "$value" != *" " ]]
}

# A path: starts with /, letters, digits and . _ ~ / -, no empty segment and
# no segment starting with a dot; OWPanel already refuses hidden files, and
# /.well-known stays open for Let's Encrypt. / itself would block everything.
owp_rules_valid_path() {
  local LC_ALL=C re='^/[A-Za-z0-9._~/-]{1,200}$'
  [[ "$1" =~ $re && "$1" != *//* && "$1" != */.* ]]
}

# Reads the rules from a file, one per line: "header<TAB>name<TAB>value" or
# "path<TAB>/path". Writes both snippets; stops at the first invalid line.
# A path ending in / blocks everything below it, else exactly that file.
# Usage: owp_rules_render <input> <headers out> <paths out>
owp_rules_render() {
  local input="$1" headers="$2" paths="$3" line kind rest name value path number=0
  local -A seen_headers=() seen_paths=()
  OWP_RULES_HEADERS=0
  OWP_RULES_PATHS=0
  printf '%s\n' "$OWP_RULES_HEAD" >"$headers"
  printf '%s\n' "$OWP_RULES_HEAD" >"$paths"

  while IFS= read -r line || [[ -n "$line" ]]; do
    number=$((number + 1))
    [[ -n "$line" ]] || continue
    kind="${line%%$'\t'*}"
    rest="${line#*$'\t'}"
    [[ "$line" == *$'\t'* ]] || owp_die "invalid_rule" "line ${number} has no tab"
    case "$kind" in
      header)
        [[ "$rest" == *$'\t'* ]] || owp_die "invalid_header" "line ${number}: name and value need a tab between them"
        name="${rest%%$'\t'*}"
        value="${rest#*$'\t'}"
        owp_rules_valid_header_name "$name" || owp_die "invalid_header" "line ${number}: header name not allowed"
        owp_rules_valid_header_value "$value" || owp_die "invalid_header" "line ${number}: header value not allowed"
        [[ -z "${seen_headers[${name,,}]+set}" ]] || owp_die "duplicate_header" "line ${number}: header given twice"
        seen_headers[${name,,}]=1
        OWP_RULES_HEADERS=$((OWP_RULES_HEADERS + 1))
        ((OWP_RULES_HEADERS <= OWP_RULES_MAX_HEADERS)) || owp_die "too_many_rules" "at most ${OWP_RULES_MAX_HEADERS} headers"
        printf 'add_header %s "%s" always;\n' "$name" "$value" >>"$headers"
        ;;
      path)
        path="$rest"
        owp_rules_valid_path "$path" || owp_die "invalid_path" "line ${number}: path not allowed"
        [[ -z "${seen_paths[$path]+set}" ]] || owp_die "duplicate_path" "line ${number}: path given twice"
        seen_paths[$path]=1
        OWP_RULES_PATHS=$((OWP_RULES_PATHS + 1))
        ((OWP_RULES_PATHS <= OWP_RULES_MAX_PATHS)) || owp_die "too_many_rules" "at most ${OWP_RULES_MAX_PATHS} paths"
        if [[ "$path" == */ ]]; then
          printf 'location ^~ %s {\n    return 404;\n}\n' "$path" >>"$paths"
        else
          printf 'location = %s {\n    return 404;\n}\n' "$path" >>"$paths"
        fi
        ;;
      *) owp_die "invalid_rule" "line ${number}: unknown kind of rule" ;;
    esac
  done <"$input"
}

# Creates both files, empty, if they are missing; existing rules stay.
# Usage: owp_rules_ensure <user>
owp_rules_ensure() {
  local file
  [[ -d "$OWP_SITE_RULES_DIR" ]] || install -d -o root -g root -m 0755 "$OWP_SITE_RULES_DIR"
  for file in "$(owp_rules_headers_file "$1")" "$(owp_rules_paths_file "$1")"; do
    [[ -f "$file" ]] && continue
    printf '%s\n' "$OWP_RULES_HEAD" >"$file"
    chmod 0644 "$file"
  done
}

# Removes both files of a website.
owp_rules_remove() {
  rm -f -- "$(owp_rules_headers_file "$1")" "$(owp_rules_paths_file "$1")"
}
