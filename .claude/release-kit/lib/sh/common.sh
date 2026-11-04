# Shared helpers for lib/sh/*.sh. Sourced, not run. bash 3.2 + BSD tools: no mapfile, no
# associative arrays, no ${x,,}. Options are --key=value; bare --flag is a boolean.
# Exit codes match lib/php/bootstrap.php: 1 fail, 3 exists, 4 conflict, 5 gates, 6 not configured, 64 usage.

YR_ARGS=("$@")
YR_DRY=0
for _a in "$@"; do [ "$_a" = "--dry-run" ] && YR_DRY=1; done
unset _a

say()  { printf '%s\n' "$*" >&2; }
step() { printf '\n→ %s\n' "$*" >&2; }
die()  { printf 'yr: %s\n' "$1" >&2; exit "${2:-1}"; }

argval() { # argval NAME [DEFAULT]
	local k="$1" d="${2:-}" a
	for a in "${YR_ARGS[@]+"${YR_ARGS[@]}"}"; do
		case "$a" in --"$k"=*) printf '%s' "${a#--$k=}"; return 0 ;; esac
	done
	printf '%s' "$d"
}
hasflag() { local a; for a in "${YR_ARGS[@]+"${YR_ARGS[@]}"}"; do [ "$a" = "--$1" ] && return 0; done; return 1; }
reqval() { local v; v="$(argval "$1")"; [ -n "$v" ] || die "missing required option --$1=<value>" 64; printf '%s' "$v"; }

# run CMD...: execute, or print it under --dry-run.
run() {
	if [ "$YR_DRY" = 1 ]; then printf '[dry-run] %s\n' "$*" >&2; return 0; fi
	"$@"
}

yrphp() { "${YR_PHP:-php}" "$YR_KIT_DIR/lib/php/cli.php" "$@"; }

# Pass --plugin/--edition/--repo/--config through to PHP commands.
passthru_opts() {
	local a out=""
	for a in "${YR_ARGS[@]+"${YR_ARGS[@]}"}"; do
		case "$a" in --plugin=*|--edition=*|--repo=*|--config=*) printf '%s\n' "$a" ;; esac
	done
}

# Load an edition's YR_* variables (see `yr config env`).
load_edition_env() {
	local envtxt
	envtxt="$(yrphp config env $(passthru_opts))" || exit $?
	eval "$envtxt"
}

expand_home() { case "$1" in "~"|"~/"*) printf '%s%s' "$HOME" "${1#\~}" ;; *) printf '%s' "$1" ;; esac; }

main_repo_of() { # main checkout of the repo containing $1
	local c
	c="$(git -C "$1" rev-parse --path-format=absolute --git-common-dir 2>/dev/null)" || return 1
	case "$c" in */.git) dirname "$c" ;; *) printf '%s' "$c" ;; esac
}

in_list() { # in_list NEEDLE "a,b,c"
	local IFS=,; local x
	for x in $2; do [ "$x" = "$1" ] && return 0; done
	return 1
}

is_short_lived() { case "$1" in release/*|support/*|yr/*) return 0 ;; *) return 1 ;; esac; }

# Path of the worktree that has BRANCH checked out, if any.
worktree_of_branch() {
	local repo="$1" br="$2" path="" line
	while IFS= read -r line; do
		case "$line" in
			"worktree "*) path="${line#worktree }" ;;
			"branch refs/heads/$br") printf '%s' "$path"; return 0 ;;
		esac
	done <<EOT
$(git -C "$repo" worktree list --porcelain)
EOT
	return 1
}

tree_is_clean() { # tracked changes or untracked (non-ignored) files make it dirty
	[ -z "$(git -C "$1" status --porcelain 2>/dev/null)" ]
}

ts() { date -u +%Y%m%d%H%M%S; }
