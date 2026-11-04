#!/usr/bin/env bash
# Build one plugin edition's zip, run the QA gates, and write it to dist/<plugin>/<edition>/.
#
#   yr build --plugin=<p> --edition=<e> --type=test|release|hotfix|support [--ref=<commit-ish>]
#            [--ticket=<id>] [--pins=<file>] [--no-fetch] [--keep-worktree] [--skip-gates=a,b]
#            [--supersede] [--dry-run [--version=X.Y.Z]]
#
# --supersede: a release zip of this version exists but the manager does not have it yet
#   (`yr builds mark-submitted` not run): move it to superseded/ and take its name.
#
# Client builds (hotfix: from a release/hotfix branch, target = its X.Y.Z; support: from a support
# branch) get the version <target>-hotfix.<n> (lib/php/builds.php). The repo's script builds the
# branch unchanged (scripts may reject such a version); then `yr builds set-version` rewrites the
# version locations inside the zip. Git keeps its version; the zip sorts above the live release
# and below the real one.
# Release builds first run the client guards (`yr support check`): the version must be above every
# unreleased client build and the commit must contain every unreleased client fix.
#
# The build always runs in a fresh, detached, throwaway worktree at the exact commit, so the
# repo's own release script can rewrite files, `rm -rf release/` and run composer/pnpm without
# touching your checkout, and two editions never share a tree. The repo's script is wrapped,
# never replaced: config `build.cmd` runs with stdin closed (no prompts) and CI=1.
#
# Order: preflight → pin shared code → prepare → build → copy + build-info.json → gates →
# (pass) dist/<file>.zip + .BUILD.json, (fail) dist/.staging/<build-id>/ kept for inspection.
# stdout: the BUILD.json (or the plan, under --dry-run). Exit 5 when a gate fails.
set -euo pipefail
. "$YR_KIT_DIR/lib/sh/common.sh"
load_edition_env

type="$(reqval type)"
case "$type" in test|release|hotfix|support) ;; *) die "--type must be test, release, hotfix or support" 64 ;; esac
ticket="$(argval ticket)"
[ "$type" != support ] || [ -n "$ticket" ] || die "support builds need --ticket=<id>" 64
ref="$(argval ref)"
if [ -z "$ref" ]; then
	[ "$type" = test ] || die "$type builds need --ref=<release or support branch / commit>" 64
	ref="$YR_DEV_BRANCH"
fi
repo="$YR_REPO"

# ---------------------------------------------------------------- preflight
step "preflight: $YR_PLUGIN/$YR_EDITION $type build from $ref"
sha="$(git -C "$repo" rev-parse --verify --quiet "$ref^{commit}")" || die "ref not found: $ref" 1
short="$(printf '%s' "$sha" | cut -c1-8)"
branch=""
if git -C "$repo" show-ref --verify --quiet "refs/heads/$ref"; then
	branch="$ref"
	if wtp="$(worktree_of_branch "$repo" "$branch")"; then
		if [ -n "$(git -C "$wtp" status --porcelain --untracked-files=no)" ]; then
			die "$branch is checked out at $wtp with uncommitted changes; they would not be in the build. Commit or stash them first." 1
		fi
	fi
	upstream="$(git -C "$repo" rev-parse --abbrev-ref --symbolic-full-name "$branch@{upstream}" 2>/dev/null || true)"
	if [ -n "$upstream" ]; then
		if hasflag no-fetch || [ "$YR_DRY" = 1 ]; then
			say "  not fetching; comparing with the last fetched $upstream"
		else
			git -C "$repo" fetch --quiet "${upstream%%/*}" "${upstream#*/}" || say "  warning: fetch failed; comparing with the last fetched $upstream"
		fi
		behind="$(git -C "$repo" rev-list --count "$branch..$upstream")"
		ahead="$(git -C "$repo" rev-list --count "$upstream..$branch")"
		[ "$behind" = 0 ] || die "$branch is $behind commit(s) behind $upstream. Pull first, so the build has the latest fixes." 1
		[ "$ahead" = 0 ] || say "  warning: $branch has $ahead commit(s) not pushed to $upstream"
	else
		say "  note: $branch has no upstream; not checking it is up to date"
	fi
fi

vjson="$(yrphp version get $(passthru_opts) --ref="$sha")"
version="$(printf '%s' "$vjson" | php -r '$d=json_decode(stream_get_contents(STDIN),true); $e=reset($d); echo $e["version"] ?? "";')"
vproblems="$(printf '%s' "$vjson" | php -r '$d=json_decode(stream_get_contents(STDIN),true); $e=reset($d); echo implode("; ", $e["problems"] ?? []);')"
[ -n "$version" ] || die "cannot read the version at $short: $vproblems" 1
if [ -n "$vproblems" ]; then
	[ "$type" = test ] && say "  warning: version locations disagree at $short: $vproblems (the versions gate will fail)" || die "version locations disagree at $short: $vproblems" 1
fi

# --version: only with --dry-run, to preview the name a release gets after its bump commit.
if [ -n "$(argval version)" ]; then
	[ "$YR_DRY" = 1 ] || die "--version is only for --dry-run previews; a real build takes the version from the commit" 64
	say "  previewing as version $(argval version) (the commit says $version)"
	version="$(argval version)"
fi
next="$(yrphp builds next $(passthru_opts) --type="$type" --version="$version" --short="$short" --ref="$ref" ${ticket:+--ticket="$ticket"})" || exit $?
file="$(printf '%s' "$next" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["file"];')"
source_version=""
client=0
if [ "$type" = hotfix ] || [ "$type" = support ]; then
	client=1
	source_version="$version"
	version="$(printf '%s' "$next" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["version"];')"
	say "  client build: the zip says $version (the branch says $source_version; git is not changed)"
fi
if [ "$type" = release ]; then
	# Client guards: every client that got a hotfix/support build must be offered this release and keep its fix.
	yrphp support check $(passthru_opts) --ref="$sha" --version="$version" >/dev/null \
		|| die "release $version would strand clients that received a client build (above). Fix that first; the kit will not build it." 1
fi
number="$(printf '%s' "$next" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["number"] ?? "";')"
resub="$(printf '%s' "$next" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["resubmission"] ?? "";')"
candidate="$(printf '%s' "$next" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["unsubmitted_candidate"] ?? "";')"
build_id="$YR_STEM-$type-$(ts)-$short"
dest="$YR_DIST_DIR/$file"
if [ -n "$candidate" ]; then
	hasflag supersede || die "$file already exists and was not handed to the manager yet. Re-run with --supersede to replace it (the old one is moved to superseded/, not deleted)." 3
fi
[ -n "$candidate" ] || [ ! -e "$dest" ] || die "$dest already exists; the kit never overwrites a build" 3

# Shared-code pin: an explicit file (orchestrator runs) or the registry default. Never float.
pins="$(argval pins)"
pins_note="none (edition ships no shared code)"
if [ -n "$YR_SHARED_PKGS" ]; then
	if [ -z "$pins" ]; then
		pins_tmp="$(mktemp -t yrpins.XXXXXX)"
		IFS=, ; for p in $YR_SHARED_PKGS; do
			pj="$(bash "$YR_KIT_DIR/lib/sh/shared.sh" show --package="$p")"
			[ "$pj" != "null" ] || die "no pin for $p. Pin one first: yr shared pin --ref=<tag> --save (the kit never builds against 'latest')" 6
			php -r '$f=$argv[1]; $a=is_file($f)&&filesize($f)?json_decode(file_get_contents($f),true):[]; $a[$argv[2]]=json_decode($argv[3],true); file_put_contents($f,json_encode($a,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));' "$pins_tmp" "$p" "$pj"
		done; unset IFS
		pins="$pins_tmp"
	fi
	pins_note="$(php -r '$a=json_decode(file_get_contents($argv[1]),true); $o=[]; foreach($a as $k=>$p){$o[]="$k {$p["ref"]} ({$p["short"]}, reports {$p["const"]})";} echo implode("; ",$o);' "$pins")"
fi

if [ "$YR_DRY" = 1 ]; then
	php -r 'echo json_encode(["dry_run"=>true,"plan"=>[
		"plugin"=>$argv[1],"edition"=>$argv[2],"type"=>$argv[3],"ref"=>$argv[4],"sha"=>$argv[5],"version"=>$argv[6],
		"source_version"=>$argv[15]?:null,
		"file"=>$argv[7],"dest"=>$argv[8],"build_id"=>$argv[9],"shared_code"=>$argv[10],
		"steps"=>array_values(array_filter(["build tree: git clone --shared <repo> ".$argv[11]."/".$argv[9]." && checkout ".$argv[5], "shared apply (exact pin)", "prepare: ".($argv[12]?:"(none)"), "build: ".$argv[13]." </dev/null CI=1", "take ".$argv[14]." → copy to dist/.staging".($argv[15] ? ", write ".$argv[6]." into its version locations" : "").", add build-info.json", "gates", "on pass: ".$argv[8]." + .BUILD.json; remove worktree"]))]],
		JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";' \
		"$YR_PLUGIN" "$YR_EDITION" "$type" "$ref" "$sha" "$version" "$file" "$dest" "$build_id" "$pins_note" "$YR_WORKTREE_ROOT" "$YR_PREPARE_CMD" "$YR_BUILD_CMD" "$YR_BUILD_OUTPUT" "$source_version"
	exit 0
fi

# ---------------------------------------------------------------- build
staging="$YR_DIST_DIR/.staging/$build_id"
mkdir -p "$staging" "$YR_DIST_DIR/logs"
log="$staging/build.log"
[ -z "$pins" ] || cp "$pins" "$staging/pins.json"
say "  version $version, commit $short, shared code: $pins_note"
say "  log: $log"

wt="$(bash "$YR_KIT_DIR/lib/sh/worktree.sh" add --repo="$repo" --name="$build_id" --ref="$sha")"
cleanup_fail() {
	say ""
	say "build failed. Kept for inspection:"
	say "  worktree: $wt   (remove: yr worktree remove --path=$wt)"
	say "  staging:  $staging"
	say "  last log lines:"
	tail -25 "$log" 2>/dev/null | sed 's/^/    /' >&2 || true
}
fail_build() { cleanup_fail; exit 1; }

if [ -n "$pins" ]; then
	IFS=, ; for p in $YR_SHARED_PKGS; do
		bash "$YR_KIT_DIR/lib/sh/shared.sh" apply --worktree="$wt" --pins="$staging/pins.json" --package="$p" >>"$log" 2>&1 || { say "pinning $p failed"; fail_build; }
	done; unset IFS
fi

# Node: the edition's `node` setting, else the worktree's .nvmrc; the newest matching nvm install goes first on PATH.
node_req="$YR_NODE"
[ -n "$node_req" ] || [ ! -f "$wt/.nvmrc" ] || node_req="$(tr -d ' v\n' < "$wt/.nvmrc")"
build_path="$PATH"
if [ -n "$node_req" ]; then
	nvm_dir="${NVM_DIR:-$HOME/.nvm}"
	node_bin="$(php -r '$b=null; foreach(glob($argv[1]."/versions/node/v*",GLOB_ONLYDIR) as $d){ $v=substr(basename($d),1); if(($v===$argv[2] || strpos($v,$argv[2].".")===0) && ($b===null || version_compare($v,$b,">"))) $b=$v; } if($b) echo $argv[1]."/versions/node/v$b/bin";' "$nvm_dir" "$node_req")"
	if [ -n "$node_bin" ]; then build_path="$node_bin:$PATH"; say "  node: $("$node_bin/node" -v) (wanted $node_req)"
	else say "  warning: node $node_req wanted but not installed under $nvm_dir; using $(node -v 2>/dev/null)"; fi
fi
build_env=(CI=1 YR_BUILD=1 COMPOSER_NO_INTERACTION=1 "YR_VERSION=$version" "YR_BUILD_TYPE=$type" "PATH=$build_path")
i=0
while [ "$i" -lt "${YR_BUILD_ENV_COUNT:-0}" ]; do
	eval "build_env+=(\"\$YR_BUILD_ENV_$i\")"
	i=$((i + 1))
done

if [ -n "$YR_PREPARE_CMD" ]; then
	step "prepare: $YR_PREPARE_CMD"
	(cd "$wt" && env "${build_env[@]}" bash -c "$YR_PREPARE_CMD" </dev/null >>"$log" 2>&1) || { say "prepare failed"; fail_build; }
fi
marker="$staging/.started"
touch "$marker"
sleep 1
step "build: $YR_BUILD_CMD   (output appended to the log)"
(cd "$wt" && env "${build_env[@]}" bash -c "$YR_BUILD_CMD" </dev/null >>"$log" 2>&1) || { say "the build command failed"; fail_build; }

raw=""
for f in $(cd "$wt" && ls -t $YR_BUILD_OUTPUT 2>/dev/null); do
	if [ "$wt/$f" -nt "$marker" ]; then raw="$wt/$f"; break; fi
done
[ -n "$raw" ] || { say "the build finished but produced no new file matching $YR_BUILD_OUTPUT"; fail_build; }
say "  built $(basename "$raw")"

cp "$raw" "$staging/raw.zip"
cp "$raw" "$staging/$file"
if [ "$client" = 1 ]; then
	# The repo's script built the branch as it is ($source_version); only the zip gets the client version.
	step "client version $version inside the zip"
	yrphp builds set-version $(passthru_opts) --zip="$staging/$file" --version="$version" >>"$log" 2>&1 \
		|| { say "writing the client version into the zip failed (see the log)"; fail_build; }
fi
yrphp builds info $(passthru_opts) --out="$staging/build-info.json" --version="$version" --type="$type" \
	--number="$number" --resubmission="$resub" ${ticket:+--ticket="$ticket"} --build-id="$build_id" ${source_version:+--source-version="$source_version"} \
	--sha="$sha" --branch="$branch" --ref="$ref" ${pins:+--shared="$staging/pins.json"}
yrphp builds inject --zip="$staging/$file" --slug="$YR_SLUG" --info="$staging/build-info.json" || fail_build

# ---------------------------------------------------------------- gates
step "QA gates"
set +e
yrphp gates $(passthru_opts) --zip="$staging/$file" --raw-zip="$staging/raw.zip" --worktree="$wt" \
	--version="$version" ${pins:+--pins="$staging/pins.json"} --out="$staging/GATES.json" \
	--skip="$(argval skip-gates)"
gates_rc=$?
set -e
if [ "$gates_rc" != 0 ]; then
	say ""
	say "QA gates failed: nothing was written to $YR_DIST_DIR."
	say "  zip and GATES.json: $staging"
	say "  worktree kept:      $wt   (remove: yr worktree remove --path=$wt)"
	exit 5
fi

[ -z "$candidate" ] || yrphp builds supersede --build-json="$candidate"
yrphp builds finalize $(passthru_opts) --zip="$staging/$file" --info="$staging/build-info.json" --gates="$staging/GATES.json" --dest="$dest"
cp "$log" "$YR_DIST_DIR/logs/$build_id.log"
cp "$staging/GATES.json" "$YR_DIST_DIR/logs/$build_id.GATES.json"
rm -rf "$staging"
if hasflag keep-worktree; then
	say "worktree kept: $wt"
else
	bash "$YR_KIT_DIR/lib/sh/worktree.sh" remove --path="$wt" >/dev/null 2>&1 || say "note: could not remove $wt"
fi
say ""
say "✓ $dest"
