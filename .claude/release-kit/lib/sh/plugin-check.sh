#!/usr/bin/env bash
# Plugin Check gate in an isolated branchpress site; nothing in your dev sites is touched.
#
#   plugin-check.sh --dir=<unzipped plugin folder> --slug=<real slug> [--out=<json>]
#
# A small host repo (~/.yayrelease/pcp/host, slug yr-pcp-host, empty WordPress) gets its own
# branchpress site. The build is copied to ~/.yayrelease/pcp/plugins/<slug>/ and mounted with
# --with, next to the plugin-check plugin (YR_PLUGIN_CHECK_PATH, else the registry's plugin_check_path,
# else the first Local site that has it). Then
# `wp plugin check <slug> --categories=plugin_repo` runs; errors there block a wp.org upload.
# Exit: 0 no errors, 1 errors found, 2 could not run (no branchpress/Docker/plugin-check).
set -uo pipefail
. "$YR_KIT_DIR/lib/sh/common.sh"

dir="$(reqval dir)"; slug="$(reqval slug)"; out="$(argval out)"
command -v branchpress >/dev/null 2>&1 || { say "branchpress is not installed (https://github.com/thithiennta/branchpress)"; exit 2; }
docker info >/dev/null 2>&1 || { say "Docker is not running"; exit 2; }
pcp_src="${YR_PLUGIN_CHECK_PATH:-}"
[ -n "$pcp_src" ] || pcp_src="$(yrphp registry list | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["settings"]["plugin_check_path"] ?? "";')"
if [ -z "$pcp_src" ]; then
	for c in "$HOME/Local Sites"/*/app/public/wp-content/plugins/plugin-check "$HOME/Local-Sites"/*/app/public/wp-content/plugins/plugin-check; do
		[ -d "$c" ] && { pcp_src="$c"; break; }
	done
fi
pcp_src="$(expand_home "$pcp_src")"
[ -n "$pcp_src" ] && { [ -f "$pcp_src/plugin.php" ] || [ -f "$pcp_src/plugin-check.php" ]; } || { say "plugin-check plugin not found. Install it in any WordPress folder and run: yr registry set --key=plugin_check_path --value=<that plugin-check folder>"; exit 2; }

base="$HOME/.yayrelease/pcp"
host="$base/host"
mount="$base/plugins/$slug"
if [ ! -d "$host/.git" ]; then
	mkdir -p "$host"
	git -C "$host" init --quiet -b main
	printf 'slug=yr-pcp-host\n' > "$host/.branchpress"
	printf '# Host repo for the release kit Plugin Check gate. Safe to delete.\n' > "$host/README.md"
	git -C "$host" add -A && git -C "$host" -c user.name=yr -c user.email=yr@localhost commit --quiet -m "pcp host"
	(cd "$host" && branchpress use --empty >/dev/null 2>&1) || true
fi
rm -rf "$mount"; mkdir -p "$(dirname "$mount")"
cp -R "$dir" "$mount"

cd "$host"
if ! branchpress up --with "$mount" --with "$pcp_src" >"$base/up.log" 2>&1; then
	rc=$?
	if [ "$rc" = 3 ]; then say "branchpress asks which test data to use for yr-pcp-host; run once: (cd $host && branchpress use --empty)"; exit 2; fi
	say "branchpress up failed (see $base/up.log)"; tail -5 "$base/up.log" >&2; exit 2
fi
branchpress wp plugin activate plugin-check >/dev/null 2>&1 || true
res="$(branchpress wp plugin check "$slug" --slug="$slug" --categories=plugin_repo --format=json 2>&1)"
rc=$?
[ -z "$out" ] || printf '%s\n' "$res" > "$out"
errors="$(printf '%s' "$res" | grep -c '"type":"ERROR"' || true)"
warnings="$(printf '%s' "$res" | grep -c '"type":"WARNING"' || true)"
printf '%s\n' "$res" | grep -E '"type":"ERROR"' | head -20 | sed -E 's/.*"code":"([^"]*)".*"message":"([^"]{0,160}).*/ERROR \1: \2/' >&2
say "Plugin Check (plugin_repo): $errors error line(s), $warnings warning line(s)"
rm -rf "$mount"
if printf '%s' "$res" | grep -q "Error:" && [ "$errors" = 0 ]; then say "$res" | tail -3; exit 2; fi
[ "$errors" = 0 ] && exit 0 || exit 1
