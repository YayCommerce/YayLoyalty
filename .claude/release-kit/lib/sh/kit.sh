#!/usr/bin/env bash
# The kit's own upkeep.
#
#   sync --from=<canonical repo or worktree> [--to=<repo or worktree>] [--dry-run]
#        Replace the shared files whole (the canonical copy is YayCommerce/Yay-Release-Kit): the
#        release-kit folder, the nine release skills, the worker agent and RELEASING.md.
#        Never touches .claude/release.config.json or support logs, and never commits.
#   hook-install [--to=<tree>] [--dry-run]   register hooks/guard-release.sh in .claude/settings.json
#   doctor                                   check the tools the kit needs
set -euo pipefail
. "$YR_KIT_DIR/lib/sh/common.sh"

SHARED_DIRS=".claude/release-kit .claude/skills/yayinit .claude/skills/yaystatus .claude/skills/yaybuild .claude/skills/yayrelease .claude/skills/yayrelease-fix .claude/skills/yayfinalize .claude/skills/yayhotfix .claude/skills/yaysupport .claude/skills/yayorchestrate"
SHARED_FILES=".claude/agents/yayrelease-worker.md RELEASING.md"

sub="${1:-doctor}"
to="$(argval to)"
[ -n "$to" ] || to="$(git rev-parse --show-toplevel)"
to="$(expand_home "$to")"

case "$sub" in
sync)
	from="$(expand_home "$(reqval from)")"
	[ -f "$from/.claude/release-kit/VERSION" ] || die "$from has no .claude/release-kit (point --from at a checkout of YayCommerce/Yay-Release-Kit)" 64
	[ "$(cd "$from" && pwd -P)" != "$(cd "$to" && pwd -P)" ] || die "--from and --to are the same tree" 64
	say "kit $(cat "$from/.claude/release-kit/VERSION") from $from → $to ($( [ -f "$to/.claude/release-kit/VERSION" ] && cat "$to/.claude/release-kit/VERSION" || echo 'not installed'))"
	flags="-a --delete --checksum --exclude=.DS_Store"
	[ "$YR_DRY" = 1 ] && flags="$flags -n -i"
	for d in $SHARED_DIRS; do
		[ -d "$from/$d" ] || die "missing in the canonical copy: $d" 1
		[ "$YR_DRY" = 1 ] || mkdir -p "$to/$d"
		rsync $flags "$from/$d/" "$to/$d/" | sed "s#^#  $d: #" >&2 || true
	done
	for f in $SHARED_FILES; do
		if [ "$YR_DRY" = 1 ]; then
			cmp -s "$from/$f" "$to/$f" 2>/dev/null && say "  $f: same" || say "  $f: would update"
		else
			mkdir -p "$(dirname "$to/$f")"; cp "$from/$f" "$to/$f"
		fi
	done
	# A shared path the repo's .gitignore hides would silently never be committed (e.g. per-skill negations).
	ignored=""
	for p in $SHARED_DIRS $SHARED_FILES; do
		probe="$p"; [ -d "$from/$p" ] && probe="$p/$(cd "$from/$p" && ls | head -1)"
		if git -C "$to" check-ignore -q "$probe" 2>/dev/null; then ignored="$ignored $p"; fi
	done
	if [ -n "$ignored" ]; then
		say ""
		say "warning: the repo's .gitignore hides these kit paths, so they would not be committed:"
		for p in $ignored; do say "  $p   (add: !$p$( [ -d "$from/$p" ] && echo /))"; done
	fi
	say ""
	say "done. Review with: git -C $to status --short; commit on each edition dev branch only after approval."
	;;
hook-install)
	settings="$to/.claude/settings.json"
	php -r '
		$f=$argv[1]; $dry=$argv[2]==="1";
		$d=is_file($f)?json_decode(file_get_contents($f),true):[];
		if(!is_array($d)){fwrite(STDERR,"invalid JSON in $f\n"); exit(1);}
		$cmd="bash \"\$CLAUDE_PROJECT_DIR/.claude/release-kit/hooks/guard-release.sh\"";
		foreach(($d["hooks"]["PreToolUse"]??[]) as $h){ foreach(($h["hooks"]??[]) as $x){ if(strpos(json_encode($x),"guard-release.sh")!==false){ fwrite(STDERR,"already installed in $f\n"); exit(0);} } }
		$d["hooks"]["PreToolUse"][]=["matcher"=>"Bash","hooks"=>[["type"=>"command","command"=>$cmd]]];
		$j=json_encode($d,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
		if($dry){fwrite(STDERR,"[dry-run] would add a PreToolUse Bash hook to $f:\n".json_encode(end($d["hooks"]["PreToolUse"]),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n"); exit(0);}
		@mkdir(dirname($f),0777,true); file_put_contents($f,$j); fwrite(STDERR,"installed the release guard hook in $f\n");
	' "$settings" "$YR_DRY"
	;;
doctor)
	ok=1
	chk() { if command -v "$1" >/dev/null 2>&1; then say "  ✓ $1 $( ($1 --version 2>/dev/null || true) | head -1)"; else say "  ✗ $1 missing${2:+ ($2)}"; [ "${3:-req}" = opt ] || ok=0; fi; }
	say "tools:"
	chk php; chk git; chk composer; chk zip; chk unzip; chk rsync; chk pnpm "" opt; chk node "" opt; chk branchpress "Plugin Check gate is skipped without it" opt; chk docker "Plugin Check gate is skipped without it" opt
	php -r 'exit(PHP_VERSION_ID>=80000?0:1);' || { say "  ✗ php 8.0+ needed for the kit"; ok=0; }
	php -r 'exit(class_exists("ZipArchive")?0:1);' || { say "  ✗ php zip extension missing"; ok=0; }
	say "registry: $(php -r 'echo getenv("YR_REGISTRY") ?: getenv("HOME")."/.config/yayrelease/registry.json";')"
	yrphp registry list | php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach($d["plugins"] as $k=>$p) fwrite(STDERR,"  $k → {$p["repo"]}\n"); foreach($d["shared_code"] as $k=>$s) fwrite(STDERR,"  pin $k: ".($s["pin"]["ref"]??"none")." (".($s["pin"]["short"]??"").", reports ".($s["pin"]["const"]??"?").")\n"); if(!$d["plugins"]) fwrite(STDERR,"  no plugins registered yet (yayinit adds them)\n");'
	[ "$ok" = 1 ] && say "doctor: ok" || { say "doctor: missing requirements"; exit 1; }
	;;
*) die "unknown: kit $sub (sync|hook-install|doctor)" 64 ;;
esac
