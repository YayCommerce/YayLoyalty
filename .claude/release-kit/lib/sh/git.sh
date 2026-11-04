#!/usr/bin/env bash
# Every git write the release skills make goes through here, so the safety rules hold in one place:
#   - never force-push, never push a deletion, never move or delete a tag;
#   - long-lived branches (each edition's dev and released branch, from the config) change only
#     through `merge` / `cherry-pick` / `commit` with --approved, which the skills pass only after
#     you said yes; they are never created, renamed or deleted here;
#   - short-lived branches (release/, support/, yr/) are never overwritten: an existing one is
#     resumed, or with --fresh renamed aside to <name>-old-<timestamp> first;
#   - pushing needs --confirmed, and push-plan shows exactly what would go first.
#
#   branch-create --name=<b> --from=<ref> [--fresh]
#   commit        --worktree=<path> --paths=a,b --message=<msg> [--approved]
#   cherry-pick   --commits=<sha,sha> --onto=<branch> --approved
#   merge         --from=<branch> --into=<branch> --approved [--message=<msg>]
#                 [--resolve-release-files [--plugin=<p>]]   settle version/changelog conflicts
#                 (lib/php/conflicts.php: higher version, both changelog entries); anything else aborts
#   tag           --name=<tag> --sha=<sha> --message-file=<file> --approved
#   push-plan     --refs=<branch,tags/x,…>
#   push          --refs=<…> --confirmed
#   delete-branch --name=<short-lived branch> --confirmed      (local only; never on the remote)
set -euo pipefail
. "$YR_KIT_DIR/lib/sh/common.sh"

repo="$(argval repo)"
[ -n "$repo" ] || repo="$(main_repo_of "$PWD")" || die "not in a git repo" 64
repo="$(main_repo_of "$(expand_home "$repo")")"
remote="$(argval remote origin)"
sub="${1:-}"

# Anything that is not a kit-made short-lived branch counts as long-lived (dev, released, main,
# personal branches): changing it needs --approved, even when the config is missing.
is_long_lived() { ! is_short_lived "$1"; }
need_approved() { hasflag approved || die "$1 changes long-lived branch $2: re-run with --approved only after the user agreed" 64; }

# A worktree with BRANCH checked out: the existing one (must be clean), or a temporary one.
TMP_WT=""
checkout_for() {
	local br="$1" wt
	if wt="$(worktree_of_branch "$repo" "$br")"; then
		tree_is_clean "$wt" || die "$br is checked out at $wt with uncommitted changes; commit or stash them, then retry" 1
		printf '%s' "$wt"
		return
	fi
	wt="$(expand_home "$(yrphp registry list | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["settings"]["worktree_root"];')")/op-$(printf '%s' "$br" | tr '/' '-')-$(ts)"
	git -C "$repo" worktree add --quiet "$wt" "$br" >&2
	TMP_WT="$wt"
	printf '%s' "$wt"
}
drop_tmp() { [ -z "$TMP_WT" ] || git -C "$repo" worktree remove --force "$TMP_WT" >/dev/null 2>&1 || true; }

check_refspec() {
	case "$1" in
		+*|:*|*:*) die "refusing refspec '$1': the kit never force-pushes or pushes deletions" 64 ;;
	esac
}

case "$sub" in
branch-create)
	name="$(reqval name)"; from="$(reqval from)"
	is_short_lived "$name" || die "the kit creates only release/, support/ and yr/ branches. Long-lived branches need your manual approval and are not created here." 64
	git -C "$repo" rev-parse --verify --quiet "$from^{commit}" >/dev/null || die "ref not found: $from" 1
	if git -C "$repo" show-ref --verify --quiet "refs/heads/$name"; then
		if hasflag fresh; then
			aside="$name-old-$(ts)"
			run git -C "$repo" branch -m "$name" "$aside"
			[ "$YR_DRY" = 1 ] || say "moved the existing $name aside to $aside (nothing deleted)"
		else
			say "$name already exists at $(git -C "$repo" rev-parse --short "$name"): resume it, or pass --fresh to set it aside and start again"
			exit 3
		fi
	elif git -C "$repo" show-ref --verify --quiet "refs/remotes/$remote/$name"; then
		say "$name exists on $remote but not locally: resume it with: git -C $repo branch --track $name $remote/$name"
		exit 3
	fi
	run git -C "$repo" branch --no-track "$name" "$from"
	[ "$YR_DRY" = 1 ] || say "created $name at $(git -C "$repo" rev-parse --short "$from")"
	;;
commit)
	wt="$(expand_home "$(reqval worktree)")"
	msg="$(reqval message)"
	br="$(git -C "$wt" branch --show-current)"
	[ -n "$br" ] || die "worktree $wt is detached; commits go on a branch" 64
	if is_long_lived "$br"; then need_approved "commit" "$br"; fi
	paths="$(reqval paths)"
	IFS=, ; set -f
	for p in $paths; do run git -C "$wt" add -- "$p"; done
	set +f; unset IFS
	if [ "$YR_DRY" = 1 ]; then say "[dry-run] would commit on $br: $msg"; exit 0; fi
	if git -C "$wt" diff --cached --quiet; then say "nothing staged; no commit made"; exit 0; fi
	git -C "$wt" commit --quiet -m "$msg"
	say "committed $(git -C "$wt" rev-parse --short HEAD) on $br: $(git -C "$wt" log -1 --format=%s)"
	git -C "$wt" rev-parse HEAD
	;;
cherry-pick)
	onto="$(reqval onto)"; commits="$(reqval commits)"
	if is_long_lived "$onto"; then need_approved "cherry-pick" "$onto"; fi
	if [ "$YR_DRY" = 1 ]; then say "[dry-run] would cherry-pick -x $commits onto $onto"; exit 0; fi
	wt="$(checkout_for "$onto")"
	trap drop_tmp EXIT
	IFS=, ; for c in $commits; do unset IFS
		if ! git -C "$wt" cherry-pick -x "$c" >&2; then
			git -C "$wt" cherry-pick --abort || true
			die "cherry-pick of $c onto $onto conflicts; nothing was changed. Port it by hand (then record it with yr support port-decision if it is not needed)." 4
		fi
	done; unset IFS
	say "cherry-picked onto $onto; now at $(git -C "$wt" rev-parse --short HEAD)"
	git -C "$wt" rev-parse HEAD
	;;
merge)
	from="$(reqval from)"; into="$(reqval into)"
	msg="$(argval message "Merge $from into $into")"
	if is_long_lived "$into"; then need_approved "merge" "$into"; fi
	if [ "$YR_DRY" = 1 ]; then say "[dry-run] would merge --no-ff $from into $into: $msg"; exit 0; fi
	wt="$(checkout_for "$into")"
	trap drop_tmp EXIT
	if ! git -C "$wt" -c merge.conflictStyle=merge merge --no-ff --no-edit -m "$msg" "$from" >&2; then
		if hasflag resolve-release-files && yrphp conflicts resolve --repo="$repo" $(passthru_opts | grep -e '^--plugin=' || true) --tree="$wt" >&2; then
			git -C "$wt" commit --quiet --no-edit
			say "merged $from into $into: $(git -C "$wt" rev-parse --short HEAD). The conflicts were settled automatically (version and changelog rules, see above); review: git -C $wt show HEAD"
			git -C "$wt" rev-parse HEAD
			exit 0
		fi
		git -C "$wt" merge --abort || true
		die "merging $from into $into conflicts; nothing was changed. Resolve it by hand." 4
	fi
	say "merged $from into $into: $(git -C "$wt" rev-parse --short HEAD)"
	git -C "$wt" rev-parse HEAD
	;;
tag)
	name="$(reqval name)"; sha="$(reqval sha)"; mf="$(reqval message-file)"
	hasflag approved || die "tags are created only after the user confirmed (re-run with --approved)" 64
	if git -C "$repo" show-ref --verify --quiet "refs/tags/$name"; then
		die "tag $name already exists at $(git -C "$repo" rev-list -n1 "$name" | cut -c1-8); the kit never moves or deletes tags" 3
	fi
	if git -C "$repo" ls-remote --exit-code --tags "$remote" "refs/tags/$name" >/dev/null 2>&1; then
		die "tag $name already exists on $remote; the kit never moves or deletes tags" 3
	fi
	run git -C "$repo" tag -a "$name" "$sha" -F "$mf"
	[ "$YR_DRY" = 1 ] || say "tagged $name → $(printf '%s' "$sha" | cut -c1-8)"
	;;
push-plan|push)
	refs="$(reqval refs)"
	specs=()
	IFS=, ; for r in $refs; do unset IFS
		check_refspec "$r"
		case "$r" in
			tags/*) n="${r#tags/}"; git -C "$repo" show-ref --verify --quiet "refs/tags/$n" || die "no such tag: $n" 1; specs+=("refs/tags/$n") ;;
			*) git -C "$repo" show-ref --verify --quiet "refs/heads/$r" || die "no such branch: $r" 1; specs+=("refs/heads/$r:refs/heads/$r") ;;
		esac
	done; unset IFS
	if [ "$sub" = push-plan ]; then
		say "Would push to $remote ($(git -C "$repo" remote get-url "$remote")):"
		for s in "${specs[@]}"; do
			src="${s%%:*}"
			case "$src" in
				refs/tags/*) say "  tag ${src#refs/tags/} → $(git -C "$repo" rev-list -n1 "$src" | cut -c1-8) $(git -C "$repo" log -1 --format=%s "$src")" ;;
				*) b="${src#refs/heads/}"
				   if git -C "$repo" show-ref --verify --quiet "refs/remotes/$remote/$b"; then
				     n="$(git -C "$repo" rev-list --count "$remote/$b..$b")"; m="$(git -C "$repo" rev-list --count "$b..$remote/$b")"
				     [ "$m" = 0 ] || die "$b is behind $remote/$b by $m commit(s); pushing would need a force. Pull first." 1
				     say "  branch $b: $n new commit(s)"
				     git -C "$repo" log --oneline "$remote/$b..$b" | head -20 | sed 's/^/      /' >&2
				   else
				     say "  branch $b: new on $remote ($(git -C "$repo" rev-list --count "$b") commits in history; tip $(git -C "$repo" log -1 --oneline "$b"))"
				   fi ;;
			esac
		done
		say ""
		say "git push --dry-run says:"
		git -C "$repo" push --dry-run --porcelain "$remote" "${specs[@]}" 2>&1 | sed 's/^/  /' >&2 || true
		exit 0
	fi
	hasflag confirmed || die "push needs --confirmed, given only after the user saw push-plan and agreed" 64
	run git -C "$repo" push "$remote" "${specs[@]}"
	;;
delete-branch)
	name="$(reqval name)"
	is_short_lived "$name" || die "only short-lived branches (release/, support/, yr/) can be deleted here" 64
	hasflag confirmed || die "delete-branch needs --confirmed" 64
	run git -C "$repo" branch -d "$name"
	[ "$YR_DRY" = 1 ] || say "deleted local $name (git refused if it was not merged). The copy on $remote is untouched; delete it on GitHub if you want."
	;;
*) die "unknown: git $sub (branch-create|commit|cherry-pick|merge|tag|push-plan|push|delete-branch)" 64 ;;
esac
