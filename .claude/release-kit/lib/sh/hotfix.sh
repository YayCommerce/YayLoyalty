#!/usr/bin/env bash
# Start a hotfix: a fix for what clients run now, without the unreleased work on the dev branch.
#
#   yr hotfix start --plugin=<p> --edition=<e> [--version=X.Y.Z] [--dry-run]
#
# 1. Base = the edition's live release: its latest tag (else its confirmed baseline commit).
# 2. Version = the patch after the live version unless --version is given; it must be above the
#    live one and have no tag yet.
# 3. Branch release/<stem>-X.Y.Z from the base (the normal release branch name, so yayrelease-fix
#    and yayfinalize treat it like any release), plus a worktree for it.
# 4. An empty start commit "chore(release): start hotfix X.Y.Z from <base>" marks it as a hotfix
#    (yaystatus). The version files are NOT bumped here: fix commits then sit on the live code
#    exactly, so they cherry-pick onto dev as cleanly as any dev fix, even next to a version line.
#    The bump to X.Y.Z comes at release prep (yayrelease's bump step, which also corrects tag
#    commits whose files still say an older version).
# Then the fix goes on that branch; client builds (`yr build --type=hotfix --ref=<branch>`) say
# X.Y.Z-hotfix.N, the tester tests that zip, and the same zip goes to clients; the release is X.Y.Z.
# stdout: JSON {branch, worktree, base, version, dev_only_commits}. Exit 3: the branch exists.
set -euo pipefail
. "$YR_KIT_DIR/lib/sh/common.sh"

sub="${1:-}"
[ "$sub" = start ] || die "unknown: hotfix $sub (start)" 64
load_edition_env
repo="$YR_REPO"

base_json="$(yrphp version base $(passthru_opts))" || exit $?
jget() { printf '%s' "$base_json" | php -r '$d=json_decode(stream_get_contents(STDIN),true); $v=$d[$argv[1]] ?? ""; echo is_bool($v) ? ($v ? "1" : "") : $v;' "$1"; }
[ -z "$(jget first_release)" ] || die "$YR_PLUGIN/$YR_EDITION was never released: there is no production to hotfix. Use the normal release flow." 1
base_ref="$(jget ref)"; base_version="$(jget version)"; base_tag="$(jget tag)"; base_source="$(jget source)"
[ -n "$base_ref" ] || die "no live commit found for $YR_PLUGIN/$YR_EDITION" 1
base_label="${base_tag:-$(printf '%s' "$base_ref" | cut -c1-8) ($base_source)}"

version="$(argval version)"
if [ -z "$version" ]; then
	version="$(php -r 'require $argv[1]."/lib/php/bootstrap.php"; require $argv[1]."/lib/php/version.php"; echo \YR\Version::bump($argv[2], "patch");' "$YR_KIT_DIR" "$base_version")"
fi
php -r 'require $argv[1]."/lib/php/bootstrap.php"; require $argv[1]."/lib/php/version.php"; exit(\YR\Version::valid($argv[2]) && version_compare($argv[2], $argv[3], ">") ? 0 : 1);' "$YR_KIT_DIR" "$version" "$base_version" \
	|| die "hotfix version $version must be a plain version above the live $base_version" 64
tag="$(printf '%s' "$YR_TAG_FORMAT" | sed -e "s|{plugin}|$YR_PLUGIN|" -e "s|{edition}|$YR_EDITION|" -e "s|{version}|$version|")"
! git -C "$repo" show-ref --verify --quiet "refs/tags/$tag" || die "$tag already exists: $version is released. Pick a higher --version." 3

branch="$(yrphp config release-branch $(passthru_opts) --version="$version")"
wt_name="$(printf '%s' "$branch" | tr '/' '-')"
dev_only="$(git -C "$repo" rev-list --count "$base_ref..$YR_DEV_BRANCH" 2>/dev/null || echo "?")"

step "hotfix $YR_PLUGIN/$YR_EDITION $version from $base_label ($base_version live)"
say "  $YR_DEV_BRANCH has $dev_only commit(s) that are not live; none of them go into this hotfix."
[ "$dev_only" = 0 ] || [ "$dev_only" = "?" ] || git -C "$repo" log --oneline --no-merges -8 "$base_ref..$YR_DEV_BRANCH" | sed 's/^/    /' >&2

if [ "$YR_DRY" = 1 ]; then
	say "[dry-run] would create $branch at $(printf '%s' "$base_ref" | cut -c1-8), a worktree $YR_WORKTREE_ROOT/$wt_name,"
	say "[dry-run] and an empty start commit: chore(release): start hotfix $version from $base_label"
	php -r 'echo json_encode(["dry_run"=>true,"branch"=>$argv[1],"worktree"=>$argv[2],"base"=>["ref"=>$argv[3],"version"=>$argv[4],"label"=>$argv[5]],"version"=>$argv[6],"dev_only_commits"=>$argv[7]], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";' \
		"$branch" "$YR_WORKTREE_ROOT/$wt_name" "$base_ref" "$base_version" "$base_label" "$version" "$dev_only"
	exit 0
fi

bash "$YR_KIT_DIR/lib/sh/git.sh" branch-create --repo="$repo" --name="$branch" --from="$base_ref"
wt="$(bash "$YR_KIT_DIR/lib/sh/worktree.sh" add --repo="$repo" --name="$wt_name" --branch="$branch")"
git -C "$wt" commit --quiet --allow-empty -m "chore(release): start hotfix $version from $base_label"
say "committed $(git -C "$wt" rev-parse --short HEAD) on $branch: chore(release): start hotfix $version from $base_label"
php -r 'echo json_encode(["branch"=>$argv[1],"worktree"=>$argv[2],"base"=>["ref"=>$argv[3],"version"=>$argv[4],"label"=>$argv[5]],"version"=>$argv[6],"dev_only_commits"=>$argv[7]], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";' \
	"$branch" "$wt" "$base_ref" "$base_version" "$base_label" "$version" "$dev_only"
say ""
say "✓ $branch at $wt. Fix the bug there, commit with yr git commit, then build the zip to test and send:"
say "    yr build --plugin=$YR_PLUGIN --edition=$YR_EDITION --type=hotfix --ref=$branch   ($version-hotfix.N inside)"
