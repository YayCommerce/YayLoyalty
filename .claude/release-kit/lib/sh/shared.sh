#!/usr/bin/env bash
# Shared code (yaycommerce/admin-shell): pin ONE revision and build every zip against it.
#
#   pin   --ref=<tag|branch|sha> [--package=yaycommerce/admin-shell] [--source=<git url|path>] [--save] [--out=<file>]
#         Resolves ref → sha, the VERSION the code reports, and the composer constraint; warns when
#         the tag and the reported VERSION disagree. --save stores it in the registry as the default pin.
#   show  [--package=…]                 the registry's current pin
#   apply --worktree=<path> --pins=<file>   in a BUILD worktree only: require the exact version so the
#         repo's own prerelease step (`composer update yaycommerce/admin-shell`) cannot float.
set -euo pipefail
. "$YR_KIT_DIR/lib/sh/common.sh"

pkg="$(argval package yaycommerce/admin-shell)"
sub="${1:-show}"

reg_get() { yrphp registry list | php -r '$d=json_decode(stream_get_contents(STDIN),true); $k=$argv[1]; $p=$argv[2];
	if ($k==="pin") echo json_encode($d["shared_code"][$p]["pin"] ?? null, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
	elseif ($k==="source") echo $d["shared_code"][$p]["source"] ?? "";
	else echo $d["settings"][$k] ?? "";' "$1" "$pkg"; }

case "$sub" in
pin)
	ref="$(reqval ref)"
	source="$(argval source "$(reg_get source)")"
	[ -n "$source" ] || source="https://github.com/YayCommerce/yaycommerce-plugin-admin-shell.git"
	cache="$(expand_home "$(reg_get cache_root)")/$(printf '%s' "$pkg" | tr '/' '_').git"
	if [ ! -d "$cache" ]; then
		say "mirroring $source into $cache (read-only cache)"
		mkdir -p "$(dirname "$cache")"
		git clone --quiet --bare "$(expand_home "$source")" "$cache"
	else
		git -C "$cache" fetch --quiet --tags "$(expand_home "$source")" '+refs/heads/*:refs/heads/*' || say "warning: could not refresh $cache; using what it has"
	fi
	sha="$(git -C "$cache" rev-parse --verify --quiet "$ref^{commit}")" || die "ref $ref not found in $pkg" 1
	vfile="$(argval version-file src/AdminShell.php)"
	const="$(git -C "$cache" show "$sha:$vfile" 2>/dev/null | sed -nE "s/.*const[[:space:]]+VERSION[[:space:]]*=[[:space:]]*'([^']+)'.*/\1/p" | head -1)"
	[ -n "$const" ] || die "could not read VERSION from $vfile at $ref" 1
	tag_ver=""
	if git -C "$cache" show-ref --verify --quiet "refs/tags/$ref"; then
		tag_ver="${ref#v}"; composer_ver="$tag_ver"
	else
		br="$(git -C "$cache" branch --contains "$sha" --format='%(refname:short)' | head -1)"
		[ -n "$br" ] || die "$sha is on no branch of $pkg; composer cannot install it" 1
		composer_ver="dev-$br#$sha"
	fi
	warnings="[]"
	if [ -n "$tag_ver" ] && [ "$tag_ver" != "$const" ]; then
		warnings="[\"tag $ref reports VERSION $const, not $tag_ver: the runtime version election compares $const\"]"
	fi
	pin="$(php -r 'echo json_encode(["package"=>$argv[1],"ref"=>$argv[2],"sha"=>$argv[3],"short"=>substr($argv[3],0,8),"const"=>$argv[4],"composer"=>$argv[5],"source"=>$argv[6],"warnings"=>json_decode($argv[7],true),"pinned_at"=>gmdate("Y-m-d\\TH:i:s\\Z")], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);' "$pkg" "$ref" "$sha" "$const" "$composer_ver" "$source" "$warnings")"
	out="$(argval out)"
	if [ -n "$out" ]; then
		if [ "$YR_DRY" = 1 ]; then say "[dry-run] would write pin file $out"; else
			mkdir -p "$(dirname "$out")"
			php -r '$f=$argv[1]; $all=is_file($f)?json_decode(file_get_contents($f),true):[]; $all[$argv[2]]=json_decode($argv[3],true); file_put_contents($f, json_encode($all, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");' "$out" "$pkg" "$pin"
		fi
	fi
	if hasflag save; then
		regf="$(php -r 'echo getenv("YR_REGISTRY") ?: getenv("HOME")."/.config/yayrelease/registry.json";')"
		if [ "$YR_DRY" = 1 ]; then say "[dry-run] would save the pin to $regf"; else
			mkdir -p "$(dirname "$regf")"
			php -r '$f=$argv[1]; $d=is_file($f)?json_decode(file_get_contents($f),true):["schema"=>1,"settings"=>[],"plugins"=>[],"shared_code"=>[]]; $d["shared_code"][$argv[2]]["pin"]=json_decode($argv[3],true); $d["shared_code"][$argv[2]]["source"]=$argv[4]; file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");' "$regf" "$pkg" "$pin" "$source"
			say "saved as the default pin for $pkg"
		fi
	fi
	printf '%s\n' "$pin"
	;;
show)
	reg_get pin
	;;
apply)
	wt="$(expand_home "$(reqval worktree)")"
	pins="$(reqval pins)"
	[ -f "$wt/.git/yr-throwaway" ] || die "apply runs only in a kit build tree (got $wt)" 64
	case "$wt" in "$(expand_home "$(reg_get worktree_root)")"/*) ;; *) die "refusing to apply a pin outside the worktree root: $wt" 64 ;; esac
	[ -f "$wt/composer.json" ] || { say "no composer.json in this edition; nothing to pin"; exit 0; }
	ver="$(php -r '$p=json_decode(file_get_contents($argv[1]),true); echo $p[$argv[2]]["composer"] ?? "";' "$pins" "$pkg")"
	[ -n "$ver" ] || die "no pin for $pkg in $pins" 1
	if ! grep -q "\"$pkg\"" "$wt/composer.json"; then say "$pkg is not required by this edition; nothing to pin"; exit 0; fi
	step "pinning $pkg to $ver in the build worktree"
	(cd "$wt" && run composer require "$pkg:$ver" --no-update --no-interaction --quiet)
	if git -C "$wt" ls-files --error-unmatch composer.lock >/dev/null 2>&1; then
		(cd "$wt" && run composer update "$pkg" --no-interaction --no-scripts --quiet)
	fi
	;;
*) die "unknown: shared $sub (pin|show|apply)" 64 ;;
esac
