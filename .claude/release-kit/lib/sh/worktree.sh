#!/usr/bin/env bash
# Worktrees for builds and release work, all under the registry's worktree_root
# (default ~/.yayrelease/worktrees): never inside the live plugin folders that Local/Docker mount.
#
#   add    --repo=<path> --name=<n> --ref=<commit>                 throwaway build clone (detached)
#   add    --repo=<path> --name=<n> --branch=<b> [--create-from=<ref>]   on a short-lived branch
#   remove --path=<p>        refuses uncommitted work unless the worktree was made throwaway
#   list | prune [--repo=<path>]
set -euo pipefail
. "$YR_KIT_DIR/lib/sh/common.sh"

root="$(expand_home "$(yrphp registry list | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["settings"]["worktree_root"];')")"
sub="${1:-list}"

case "$sub" in
add)
	repo="$(main_repo_of "$(expand_home "$(reqval repo)")")" || die "not a git repo" 64
	name="$(reqval name)"
	path="$root/$name"
	[ -e "$path" ] && die "worktree path exists: $path (resume it, or remove it with: yr worktree remove --path=$path)" 3
	ref="$(argval ref)"; branch="$(argval branch)"; from="$(argval create-from)"
	if [ -n "$branch" ]; then
		if git -C "$repo" show-ref --verify --quiet "refs/heads/$branch"; then
			if other="$(worktree_of_branch "$repo" "$branch")"; then
				die "$branch is already checked out at $other; work there" 4
			fi
			run git -C "$repo" worktree add --quiet "$path" "$branch" >&2
		else
			[ -n "$from" ] || die "branch $branch does not exist; pass --create-from=<ref> to create it" 64
			is_short_lived "$branch" || die "the kit only creates short-lived branches (release/, support/, yr/); $branch needs your manual approval" 64
			run git -C "$repo" worktree add --quiet -b "$branch" "$path" "$from" >&2
		fi
	else
		# Build trees are throwaway clones that borrow the repo's objects (--shared: fast, small), not
		# worktrees: a worktree's .git is a file, which repo release scripts would copy into the zip
		# (their .distignore excludes the .git folder only). Commits never happen here.
		[ -n "$ref" ] || die "pass --ref=<commit> (detached build tree) or --branch=<name> (worktree)" 64
		sha="$(git -C "$repo" rev-parse --verify --quiet "$ref^{commit}")" || die "ref not found: $ref" 1
		run mkdir -p "$root"
		run git clone --quiet --shared --no-checkout "$repo" "$path" >&2
		run git -C "$path" checkout --quiet --detach "$sha" >&2
		if [ "$YR_DRY" = 0 ]; then
			touch "$path/.git/yr-throwaway"
		fi
	fi
	printf '%s\n' "$path"
	;;
remove)
	path="$(expand_home "$(reqval path)")"
	case "$path" in "$root"/*) ;; *) die "refusing to remove a worktree outside $root: $path" 64 ;; esac
	[ -d "$path" ] || { say "already gone: $path"; exit 0; }
	if [ -f "$path/.git/yr-throwaway" ]; then
		run rm -rf "$path"   # throwaway build clone: nothing in it is ever committed
		[ "$YR_DRY" = 1 ] || say "removed $path"
		exit 0
	fi
	gd="$(git -C "$path" rev-parse --absolute-git-dir)"
	repo="$(main_repo_of "$path")"
	if [ -f "$gd/yr-throwaway" ]; then
		run git -C "$repo" worktree remove --force "$path"
	else
		if [ -n "$(git -C "$path" status --porcelain --untracked-files=no)" ]; then
			die "worktree has uncommitted changes to tracked files: $path. Commit or discard them first." 1
		fi
		# Untracked build output (node_modules, vendor, release/) is expected; tracked work is safe in git.
		run git -C "$repo" worktree remove --force "$path"
	fi
	[ "$YR_DRY" = 1 ] || say "removed $path"
	;;
list)
	repo="$(argval repo)"
	if [ -n "$repo" ]; then git -C "$(expand_home "$repo")" worktree list; else ls -1 "$root" 2>/dev/null || true; fi
	;;
prune)
	run git -C "$(expand_home "$(reqval repo)")" worktree prune -v
	;;
*) die "unknown: worktree $sub (add|remove|list|prune)" 64 ;;
esac
