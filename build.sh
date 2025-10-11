#!/usr/bin/env bash
# ensure we’re running under bash even if user types `sh build.sh`
if [ -z "${BASH_VERSION:-}" ]; then exec bash "$0" "$@"; fi

# build.sh — driver with cfg introspection + numbered lists (nounset-safe)
set -u  # keep going on failures; we capture rc manually

err(){ echo "[ERR] $*" >&2; }
log(){ printf '%s\n' "$*"; }

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$script_dir"
cfg_dir="$repo_root/cfg"

[[ -d "$cfg_dir" ]] || { err "cfg folder not found: $cfg_dir"; exit 1; }
[[ -f "$cfg_dir/plugin_build.sh" ]] || { err "plugin_build.sh not found in $cfg_dir"; exit 1; }
[[ -f "$cfg_dir/suite_release.sh" ]] || { err "suite_release.sh not found in $cfg_dir"; exit 1; }

shopt -s nullglob
all_cfgs=( "$cfg_dir"/*.cfg )
shopt -u nullglob
((${#all_cfgs[@]})) || { err "No .cfg files in $cfg_dir"; exit 1; }

# ---- Helpers (nounset-safe; no eval/source) ----
kv_get () {
  # usage: kv_get KEY FILE -> prints value if KEY=... exists; returns 0/1
  local key="${1:-}" cfgpath="${2:-}"
  [[ -n "$key" && -n "$cfgpath" && -f "$cfgpath" ]] || return 1
  awk -v k="$key" '
    { gsub("\r",""); }                      # normalize CRLF
    /^[[:space:]]*(#|;)/ { next }           # skip comments
    /^[[:space:]]*$/      { next }          # skip blanks
    $0 ~ "^[[:space:]]*"k"[[:space:]]*=" {
      val = $0
      sub("^[[:space:]]*"k"[[:space:]]*=","",val)
      gsub(/^[[:space:]]*"/,"",val); gsub(/"$/,"",val)
      gsub(/^[[:space:]]*'\''/,"",val); gsub(/'\''$/,"",val)
      gsub(/[[:space:]]+$/,"",val)
      print val; exit
    }
  ' "$cfgpath"
}

is_suite_cfg () {
  local cfgpath="${1:-}"
  [[ -n "$cfgpath" && -f "$cfgpath" ]] || return 1
  local base
  base="$(basename "$cfgpath" | tr '[:upper:]' '[:lower:]')"
  # filename fallback
  if [[ "$base" == "suite.cfg" || "$base" == *"-suite.cfg" || "$base" == *"suite"* ]]; then
    return 0
  fi
  # content heuristics
  if grep -qiE '^[[:space:]]*(SUITE(_NAME)?|RELEASE(_GROUP)?|SUITE_PLUGINS|PLUGINS)[[:space:]]*=' "$cfgpath"; then
    return 0
  fi
  return 1
}

# Partition cfgs and build labels
plugin_cfgs=(); suite_cfgs=()
plugin_labels=(); suite_labels=()

for c in "${all_cfgs[@]}"; do
  if is_suite_cfg "$c"; then
    suite_cfgs+=( "$c" )
    name="$(kv_get SUITE_NAME "$c" || true)"
    [[ -z "${name:-}" ]] && name="$(basename "$c" .cfg)"
    suite_labels+=( "$name" )
  else
    plugin_cfgs+=( "$c" )
    pname="$(kv_get PLUGIN_NAME "$c" || true)"
    pslug="$(kv_get PLUGIN_SLUG "$c" || true)"
    label="${pname:-}"
    [[ -z "$label" ]] && label="${pslug:-}"
    [[ -z "$label" ]] && label="$(basename "$c" .cfg)"
    plugin_labels+=( "$label" )
  fi
done

menu() {
  echo "========================================="
  echo "Build options:"
  echo "  1) Build ONE plugin"
  echo "  2) Run ONE suite release"
  echo "  3) Build ALL plugins, then ALL suites"
  echo "  Q) Quit"
  echo "========================================="
}

print_list() {
  # $1 kind, $2[@] paths, $3[@] labels
  local kind="$1"; shift
  local -n paths=$1; shift
  local -n labels=$1
  local n=${#paths[@]}
  echo "Select $kind:"
  if (( n == 0 )); then
    echo "  (no $kind cfgs found in $cfg_dir)"; return 1
  fi
  local i
  for ((i=0;i<n;i++)); do
    printf "  %2d) %s  [%s]\n" "$((i+1))" "${labels[$i]}" "$(basename "${paths[$i]}")"
  done
  return 0
}

read_choice() {
  local max="$1" prompt="$2" input
  while :; do
    read -rp "$prompt " input || return 1
    if [[ "$input" =~ ^[0-9]+$ ]] && (( input>=1 && input<=max )); then
      echo "$input"; return 0
    fi
    echo "Please enter a number between 1 and $max."
  done
}

run_plugin() {
  local cfg="$1"; shift
  local flags=("$@")
  echo "=== PLUGIN: $(basename "$cfg") ==="
  set +e
  bash "$cfg_dir/plugin_build.sh" --cfg="$cfg" "${flags[@]}"
  local rc=$?
  set -e
  echo "→ plugin_build.sh exited $rc"
  echo
  return $rc
}

run_suite() {
  local cfg="$1"; shift
  local flags=("$@")
  echo "=== SUITE: $(basename "$cfg") ==="
  set +e
  bash "$cfg_dir/suite_release.sh" --cfg="$cfg" "${flags[@]}"
  local rc=$?
  set -e
  echo "→ suite_release.sh exited $rc"
  echo
  return $rc
}

# Prompt once for flags
read -rp "Extra flags for plugin_build.sh [--no-pause]: " plugin_flags_line || plugin_flags_line=""
plugin_flags_line="${plugin_flags_line:---no-pause}"
read -rp "Extra flags for suite_release.sh [--rebuild --cfg-dir=cfg]: " suite_flags_line || suite_flags_line=""
suite_flags_line="${suite_flags_line:---rebuild --cfg-dir=cfg}"
# shellcheck disable=SC2206
plugin_flags=( $plugin_flags_line )
suite_flags=( $suite_flags_line )

failures=()

while :; do
  menu
  read -rp "Choose an option: " opt || break
  case "${opt^^}" in
    1)
      print_list "plugin" plugin_cfgs plugin_labels || { echo; continue; }
      idx="$(read_choice "${#plugin_cfgs[@]}" "Number:")" || { echo; continue; }
      sel="${plugin_cfgs[$((idx-1))]}"
      run_plugin "$sel" "${plugin_flags[@]}"; rc=$?
      (( rc!=0 )) && failures+=( "PLUGIN:$(basename "$sel") rc=$rc" )
      ;;
    2)
      print_list "suite" suite_cfgs suite_labels || { echo; continue; }
      idx="$(read_choice "${#suite_cfgs[@]}" "Number:")" || { echo; continue; }
      sel="${suite_cfgs[$((idx-1))]}"
      run_suite "$sel" "${suite_flags[@]}"; rc=$?
      (( rc!=0 )) && failures+=( "SUITE:$(basename "$sel") rc=$rc" )
      ;;
    3)
      for ((i=0;i<${#plugin_cfgs[@]};i++)); do
        run_plugin "${plugin_cfgs[$i]}" "${plugin_flags[@]}"; rc=$?
        (( rc!=0 )) && failures+=( "PLUGIN:$(basename "${plugin_cfgs[$i]}") rc=$rc" )
      done
      for ((i=0;i<${#suite_cfgs[@]};i++)); do
        run_suite "${suite_cfgs[$i]}" "${suite_flags[@]}"; rc=$?
        (( rc!=0 )) && failures+=( "SUITE:$(basename "${suite_cfgs[$i]}") rc=$rc" )
      done
      ;;
    Q) break ;;
    *) echo "Unknown option." ;;
  esac
done

if ((${#failures[@]})); then
  echo "---------- SUMMARY (failures) ----------"
  printf '%s\n' "${failures[@]}"
  exit 1
fi

echo "Done. ✅"
