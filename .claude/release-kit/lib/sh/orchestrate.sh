#!/usr/bin/env bash
# The deterministic half of yayorchestrate: plan a run, write one task file per worker, collect
# the reports into one review. Spawning the worker agents is the skill's job.
#
#   plan    --action=status|build|release|release-fix --targets=<plugin:edition,plugin:edition,…>
#           [--pin=<shared-code ref>] [--dry-run]
#           Resolves each target through the registry (any repo), checks its config and baseline,
#           fetches each repo once, pins ONE shared-code revision for the whole run, preflights every
#           row (`yr build --dry-run`), groups editions that share a dev branch for release actions,
#           and writes ~/.yayrelease/runs/<run-id>/{RUN.json,pins.json,tasks/*.json,PLAN.md}.
#           --dry-run prints the same plan without writing the run folder.
#   collect --run=<run-id|path>    reports → REVIEW.md (one table, all changelog drafts, conflicts)
#   handoff --run=<run-id|path>    combined HANDOFF-<date>.md for the approved, built rows
set -euo pipefail
. "$YR_KIT_DIR/lib/sh/common.sh"

sub="${1:-plan}"
runs="$HOME/.yayrelease/runs"

run_dir_of() { case "$1" in /*|~*) expand_home "$1" ;; *) printf '%s/%s' "$runs" "$1" ;; esac; }

case "$sub" in
plan)
	action="$(reqval action)"
	case "$action" in status|build|release|release-fix) ;; *) die "--action must be status, build, release or release-fix" 64 ;; esac
	targets="$(reqval targets)"
	run_id="$(date -u +%Y%m%d-%H%M%S)-$action"
	dir="$runs/$run_id"
	tmp="$(mktemp -d -t yrrun.XXXXXX)"
	reg="$(yrphp registry list)"
	conc="$(printf '%s' "$reg" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo (int)$d["settings"]["concurrency"];')"

	# One pin for the whole run.
	pin_ref="$(argval pin)"
	if [ -z "$pin_ref" ]; then
		pin_ref="$(printf '%s' "$reg" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["shared_code"]["yaycommerce/admin-shell"]["pin"]["ref"] ?? "";')"
	fi
	if [ "$action" != status ]; then
		[ -n "$pin_ref" ] || die "no shared-code pin. Pass --pin=<admin-shell tag> or save one: yr shared pin --ref=<tag> --save" 6
		bash "$YR_KIT_DIR/lib/sh/shared.sh" pin --ref="$pin_ref" --out="$tmp/pins.json" >/dev/null
	fi

	rows="[]"
	fetched=","
	IFS=, ; set -f
	for t in $targets; do
		unset IFS
		plugin="${t%%:*}"; edition="${t#*:}"; [ "$edition" != "$t" ] || edition=""
		repo="$(printf '%s' "$reg" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["plugins"][$argv[1]]["repo"] ?? "";' "$plugin")"
		row_status="ready"; note=""; plan_json="null"; group=""; version=""
		if [ -z "$repo" ]; then
			row_status="skipped"; note="not in the registry (run yayinit in its repo)"
		elif [ -z "$edition" ]; then
			row_status="skipped"; note="no edition given (use $plugin:<edition>)"
		else
			if ! envtxt="$(cd "$repo" && yrphp config env --repo="$repo" --plugin="$plugin" --edition="$edition" 2>&1)"; then
				row_status="skipped"; note="$(printf '%s' "$envtxt" | tail -1)"
			else
				eval "$envtxt"
				group="$YR_BRANCH_GROUP"
				if [ "$action" = release ] || [ "$action" = release-fix ]; then
					if ! (cd "$repo" && yrphp version propose --repo="$repo" --plugin="$plugin" --edition="$edition" >"$tmp/propose.json" 2>"$tmp/err"); then
						row_status="skipped"; note="$(tail -1 "$tmp/err")"
					else
						version="$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo ($d["proposed"] ?? "none");' "$tmp/propose.json")"
					fi
				fi
				if [ "$row_status" = ready ] && [ "$action" != status ]; then
					case "$fetched" in *",$repo,"*) ;; *)
						if [ "$YR_DRY" = 0 ]; then git -C "$repo" fetch --quiet "$YR_REMOTE" || note="fetch failed; used last fetched state"; fi
						fetched="$fetched$repo," ;;
					esac
					if [ "$action" = build ]; then
						if plan_out="$(cd "$repo" && bash "$YR_KIT_DIR/lib/sh/build.sh" --repo="$repo" --plugin="$plugin" --edition="$edition" --type=test --pins="$tmp/pins.json" --no-fetch --dry-run 2>"$tmp/err")"; then
							plan_json="$(printf '%s' "$plan_out" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo json_encode($d["plan"]);')"
							version="$(printf '%s' "$plan_json" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["version"];')"
						else
							row_status="blocked"; note="preflight: $(grep 'yr:' "$tmp/err" | tail -1 | sed 's/^yr: //')"
						fi
					fi
				fi
			fi
		fi
		rows="$(php -r '$r=json_decode($argv[1],true); $r[]=["plugin"=>$argv[2],"edition"=>$argv[3],"repo"=>$argv[4],"status"=>$argv[5],"note"=>$argv[6],"branch_group"=>$argv[7],"version"=>$argv[8],"plan"=>json_decode($argv[9],true)]; echo json_encode($r);' "$rows" "$plugin" "$edition" "$repo" "$row_status" "$note" "$group" "$version" "$plan_json")"
		IFS=,
	done
	set +f; unset IFS

	# Workers: one per edition, one per branch group for release actions.
	run_json="$(php -r '
		$rows=json_decode($argv[1],true); $action=$argv[2]; $conc=(int)$argv[3]; $pins=is_file($argv[4])?json_decode(file_get_contents($argv[4]),true):null;
		$workers=[];
		foreach($rows as $r){ if($r["status"]!=="ready") continue;
			$key = in_array($action,["release","release-fix"],true) ? $r["plugin"]."@".$r["branch_group"] : $r["plugin"].":".$r["edition"];
			$workers[$key]["plugin"]=$r["plugin"]; $workers[$key]["repo"]=$r["repo"]; $workers[$key]["editions"][]=$r["edition"]; $workers[$key]["version"]=$r["version"]; }
		$i=0; $list=[]; foreach($workers as $k=>$w){ $w["id"]=preg_replace("/[^a-z0-9]+/","-",strtolower($k)); $w["batch"]=intdiv($i,max(1,$conc))+1; $list[]=$w; $i++; }
		echo json_encode(["run_id"=>$argv[5],"action"=>$action,"created"=>gmdate("c"),"concurrency"=>$conc,"shared_code"=>$pins,"rows"=>$rows,"workers"=>$list], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
	' "$rows" "$action" "$conc" "$tmp/pins.json" "$run_id")"

	plan_md="$(printf '%s' "$run_json" | php -r '
		$d=json_decode(stream_get_contents(STDIN),true);
		$o=["# Run {$d["run_id"]} ({$d["action"]})",""];
		$o[]="Shared code for every row: ".($d["shared_code"]? implode("; ", array_map(fn($k,$p)=>"$k {$p["ref"]} ({$p["short"]}, reports {$p["const"]})".($p["warnings"]?" ⚠ ".implode(" ",$p["warnings"]):""), array_keys($d["shared_code"]), $d["shared_code"])) : "n/a");
		$o[]="Concurrency: {$d["concurrency"]}"; $o[]="";
		$o[]="| # | Plugin | Edition | Status | Version | Plan / note |"; $o[]="|---|---|---|---|---|---|";
		foreach($d["rows"] as $i=>$r){ $p=$r["plan"]; $o[]="| ".($i+1)." | {$r["plugin"]} | {$r["edition"]} | {$r["status"]} | ".($r["version"]?:"—")." | ".($p? "`{$p["file"]}` from ".substr($p["sha"],0,8):"").($r["note"]?" ".$r["note"]:"")." |"; }
		$o[]=""; $o[]="Workers:"; foreach($d["workers"] as $w){ $o[]="- batch {$w["batch"]}: {$w["id"]} → ".implode(", ",$w["editions"])." in {$w["repo"]}"; }
		if(!$d["workers"]) $o[]="- none (nothing is ready)";
		echo implode("\n",$o)."\n";')"

	if [ "$YR_DRY" = 1 ]; then
		say "[dry-run] would create run folder $dir with RUN.json, pins.json, PLAN.md and one task file per worker"
		printf '%s\n' "$plan_md"
		rm -rf "$tmp"
		exit 0
	fi
	mkdir -p "$dir/tasks"
	printf '%s\n' "$run_json" > "$dir/RUN.json"
	[ ! -f "$tmp/pins.json" ] || cp "$tmp/pins.json" "$dir/pins.json"
	printf '%s' "$run_json" | php -r '
		$d=json_decode(stream_get_contents(STDIN),true); $dir=$argv[1];
		foreach($d["workers"] as $w){
			$task=["run_id"=>$d["run_id"],"action"=>$d["action"],"worker_id"=>$w["id"],"plugin"=>$w["plugin"],"editions"=>$w["editions"],"repo"=>$w["repo"],
				"pins_file"=>is_file("$dir/pins.json")?"$dir/pins.json":null,"version"=>$w["version"]??null,"approved_test_build"=>null,"changelog_draft"=>null,"feedback"=>null,"live_somewhere"=>null,
				"report_files"=>array_map(fn($e)=>"(the edition dist folder)/REPORT.json",$w["editions"])];
			file_put_contents("$dir/tasks/{$w["id"]}.json", json_encode($task, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
		}' "$dir"
	printf '%s\n' "$plan_md" > "$dir/PLAN.md"
	printf '%s\n' "$plan_md"
	say "run folder: $dir"
	rm -rf "$tmp"
	;;
collect)
	dir="$(run_dir_of "$(reqval run)")"
	[ -f "$dir/RUN.json" ] || die "no run at $dir" 1
	files="$(php -r '
		$d=json_decode(file_get_contents($argv[1]."/RUN.json"),true); $out=[];
		foreach($d["workers"] as $w){ foreach($w["editions"] as $e){
			$cfgf=$w["repo"]."/.claude/release.config.json"; $dist="dist";
			if(is_file($cfgf)){ $c=json_decode(file_get_contents($cfgf),true); $dist=$c["settings"]["dist"]??"dist"; }
			$f=($dist[0]==="/"?$dist:$w["repo"]."/".$dist)."/".$w["plugin"]."/".$e."/REPORT.json";
			if(is_file($f) && filemtime($f) >= strtotime($d["created"])) $out[]=$f; else fwrite(STDERR,"no report yet: {$w["plugin"]}/$e ($f)\n"); } }
		echo implode(",",$out);' "$dir")"
	[ -n "$files" ] || die "no reports in this run yet" 1
	yrphp report collect --files="$files" --out="$dir/REVIEW.md"
	say "review: $dir/REVIEW.md"
	;;
handoff)
	dir="$(run_dir_of "$(reqval run)")"
	builds="$(reqval builds)"
	yrphp handoff combine --builds="$builds" --out="$dir/HANDOFF-$(date -u +%Y-%m-%d).md"
	;;
*) die "unknown: orchestrate $sub (plan|collect|handoff)" 64 ;;
esac
