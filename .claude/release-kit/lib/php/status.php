<?php
/**
 * Read-only status for one plugin or every registered plugin (the yaystatus skill). Reads git
 * refs and dist/ only; never fetches unless --fetch is given.
 */

declare(strict_types=1);

namespace YR;

final class Status {
	public static function edition( Config $cfg, string $plugin, string $k ): array {
		$e    = $cfg->edition( $plugin, $k );
		$repo = $cfg->repo;
		$row  = [ 'plugin' => $plugin, 'edition' => $k, 'label' => $e['label'] ?? null, 'target' => $e['target'], 'dev_branch' => $e['dev_branch'], 'problems' => [] ];

		if ( ! ref_exists( $repo, $e['dev_branch'] ) ) {
			$row['problems'][] = "dev branch {$e['dev_branch']} not found locally";
			return $row;
		}
		$tag  = Version::latest_tag( $cfg, $plugin, $k );
		$base = Version::base( $cfg, $plugin, $k );
		$row['released'] = $tag ? [ 'version' => $tag[1], 'tag' => $tag[0], 'sha' => substr( $tag[2], 0, 8 ) ] : ( ! empty( $base['first_release'] ) ? [ 'version' => 'never', 'tag' => null, 'sha' => null ] : null );
		if ( ! $tag ) {
			$row['problems'][] = $base ? 'baseline confirmed but its tag is not created yet' : 'no release tag and no confirmed baseline: release skills skip this edition (run yayinit)';
		}
		$dev = Version::check( Version::read( $cfg, $e, $e['dev_branch'], null ) );
		$row['dev_version'] = $dev['version'];
		if ( $tag && $dev['version'] && Version::cmp( $dev['version'], $tag[1] ) < 0 ) {
			$row['problems'][] = "dev branch says {$dev['version']} but {$tag[1]} is released (the release was bumped without a commit); the next release must be above {$tag[1]}";
		}
		foreach ( $dev['problems'] as $p ) {
			$row['problems'][] = "dev branch: $p";
		}
		if ( ! empty( $e['released_branch'] ) ) {
			$rb = ref_exists( $repo, $e['released_branch'] ) ? $e['released_branch'] : ( ref_exists( $repo, 'origin/' . $e['released_branch'] ) ? 'origin/' . $e['released_branch'] : null );
			$row['released_branch'] = $rb ? [ 'name' => $rb, 'version' => Version::check( Version::read( $cfg, $e, $rb, null ) )['version'] ] : null;
			if ( ! $rb && empty( $base['first_release'] ) ) {
				$row['problems'][] = "released branch {$e['released_branch']} not found";
			}
		}
		if ( $base && ! empty( $base['first_release'] ) ) {
			$row['unreleased'] = [ 'commits' => (int) git( $repo, [ 'rev-list', '--count', $e['dev_branch'] ] ), 'user_facing' => 0, 'by_type' => [], 'since' => 'first release pending' ];
			$row['problems']   = array_values( array_filter( $row['problems'], static fn( $p ) => strpos( $p, 'tag is not created' ) === false ) );
		} elseif ( $base ) {
			$commits = Commits::log( $repo, $base['ref'] . '..' . $e['dev_branch'] );
			$user    = array_filter( $commits, static fn( $c ) => ! $c['internal'] );
			$types   = [];
			foreach ( $user as $c ) {
				$types[ $c['type'] ] = ( $types[ $c['type'] ] ?? 0 ) + 1;
			}
			$row['unreleased'] = [ 'commits' => count( $commits ), 'user_facing' => count( $user ), 'by_type' => $types, 'since' => $base['source'] ];
		}
		// Short-lived branches of this edition (and its branch group).
		$stems = array_unique( [ $cfg->stem( $plugin, $k ), $cfg->plugin( $plugin )['group_names'][ $e['branch_group'] ] ?? $plugin ] );
		$open  = [];
		foreach ( $stems as $s ) {
			foreach ( [ "release/$s-", "support/$s-" ] as $prefix ) {
				$list = git_try( $repo, [ 'for-each-ref', '--format=%(refname:short)', "refs/heads/$prefix*", "refs/remotes/*/$prefix*" ] ) ?? '';
				foreach ( array_filter( explode( "\n", $list ) ) as $b ) {
					$open[ preg_replace( '#^[^/]+/(?=(release|support)/)#', '', $b ) ] = true;
				}
			}
		}
		$row['open_branches'] = array_keys( $open );
		// Hotfix branches (start commit made by `yr hotfix start`) and branches a newer release overtook.
		$row['hotfixes'] = [];
		foreach ( $row['open_branches'] as $b ) {
			if ( strncmp( $b, 'release/', 8 ) !== 0 || ! preg_match( '/-(\d+\.\d+(?:\.\d+){0,2})$/', $b, $vm ) ) {
				continue;
			}
			$ref = ref_exists( $repo, $b ) ? $b : ( ref_exists( $repo, 'origin/' . $b ) ? 'origin/' . $b : null );
			if ( ! $ref ) {
				continue;
			}
			// Made by `yr hotfix start`: its start commit is on the branch and not on dev.
			$is_hotfix = trim( (string) git_try( $repo, [ 'log', '-n', '1', '--format=%h', '--grep=^chore(release): start hotfix', $e['dev_branch'] . '..' . $ref ] ) ) !== '';
			if ( $is_hotfix ) {
				$row['hotfixes'][] = [ 'branch' => $b, 'version' => $vm[1] ];
			}
			if ( ref_exists( $repo, 'refs/tags/' . $cfg->tag_name( $plugin, $k, $vm[1] ) ) ) {
				$row['problems'][] = "$b was released as $vm[1] (tagged); the local branch can be deleted (yayfinalize offers it)";
			} elseif ( $tag && Version::cmp( $vm[1], $tag[1] ) < 0 ) {
				$row['problems'][] = "$b ($vm[1]) is below the released {$tag[1]}: superseded, it will never be tagged. Its fixes reached clients through {$tag[1]} only if they are in it (yr support check).";
			}
		}
		$latest = Builds::latest( $cfg->dist_dir( $plugin, $k ), 'test' );
		$row['latest_test_build'] = $latest ? [ 'file' => $latest['zip']['file'], 'date' => $latest['date'], 'sha' => $latest['git']['short'], 'branch' => $latest['git']['branch'] ?? null ] : null;
		if ( $latest && ! empty( $latest['git']['branch'] ) && $latest['git']['branch'] !== $e['dev_branch'] ) {
			$row['latest_test_build']['from_other_branch'] = true; // e.g. a hotfix test build: not comparable with dev
		} elseif ( $latest && ref_exists( $repo, $latest['git']['sha'] ) ) {
			$row['latest_test_build']['commits_since'] = (int) git( $repo, [ 'rev-list', '--count', $latest['git']['sha'] . '..' . $e['dev_branch'] ] );
		}
		$unrel = SupportLog::rows( SupportLog::read( $cfg, $e, $e['dev_branch'] ), 'unreleased' );
		$row['support_unreleased'] = array_map( static fn( $r ) => "{$r['ticket']}: {$r['fix']}" . ( $r['build_version'] !== '' ? " (client build {$r['build_version']})" : '' ), $unrel );
		$row['client_builds'] = [];
		foreach ( $unrel as $r ) {
			$in_dev = SupportLog::fix_in( $cfg, $e['dev_branch'], $r );
			if ( $r['build_version'] !== '' ) {
				$row['client_builds'][] = [ 'ticket' => $r['ticket'], 'build_version' => $r['build_version'], 'fix_on_dev' => $in_dev ];
			}
			if ( ! $in_dev ) {
				$row['problems'][] = "ticket {$r['ticket']}'s fix is not on {$e['dev_branch']} yet: a release from it would be refused (it would remove the fix from that client). Bring it over (cherry-pick), or mark it wont-release.";
			}
		}
		$row['kit'] = [
			'config_blob' => git_try( $repo, [ 'rev-parse', $e['dev_branch'] . ':.claude/release.config.json' ] ),
			'kit_version' => trim( (string) git_show( $repo, $e['dev_branch'], '.claude/release-kit/VERSION' ) ) ?: null,
		];
		return $row;
	}

	public static function plugin( Config $cfg, string $plugin, bool $ports ): array {
		$rows = [];
		foreach ( array_keys( $cfg->plugin( $plugin )['editions'] ) as $k ) {
			$rows[ $k ] = self::edition( $cfg, $plugin, $k );
		}
		// Cross-edition checks.
		$plugin_problems = [];
		if ( $cfg->plugin( $plugin )['version_policy'] === 'shared' ) {
			$vs = array_unique( array_filter( array_column( $rows, 'dev_version' ) ) );
			if ( count( $vs ) > 1 ) {
				$plugin_problems[] = 'shared version policy, but dev branches differ: ' . implode( ', ', array_map( static fn( $r ) => "{$r['edition']} {$r['dev_version']}", $rows ) );
			}
		}
		$blobs = array_unique( array_filter( array_map( static fn( $r ) => $r['kit']['config_blob'] ?? null, $rows ) ) );
		$missing = array_keys( array_filter( $rows, static fn( $r ) => empty( $r['kit']['config_blob'] ) ) );
		if ( count( $blobs ) > 1 ) {
			$plugin_problems[] = 'release.config.json differs between dev branches; run `yr kit sync` and commit it on each';
		}
		if ( $missing ) {
			$plugin_problems[] = 'release.config.json not committed on: ' . implode( ', ', array_unique( array_map( static fn( $k ) => $rows[ $k ]['dev_branch'], $missing ) ) );
		}
		$unported = [];
		if ( $ports ) {
			foreach ( Ports::pairs( $cfg, $plugin, null ) as [ $a, $b ] ) {
				$s = Ports::scan( $cfg, $plugin, $a, $b, false, 60 );
				if ( $s['unported'] ) {
					$unported[] = [ 'from' => $a, 'to' => $b, 'count' => count( $s['unported'] ), 'examples' => array_map( static fn( $c ) => "{$c['short']} {$c['subject']}", array_slice( $s['unported'], 0, 5 ) ), 'window' => $s['window'] ];
				}
			}
		}
		return [ 'plugin' => $plugin, 'repo' => $cfg->repo, 'version_policy' => $cfg->plugin( $plugin )['version_policy'], 'editions' => $rows, 'unported' => $unported, 'problems' => $plugin_problems ];
	}

	public static function markdown( array $plugins, array $unconfigured ): string {
		$o = [ '| Plugin | Edition | Target | Released | Dev | Unreleased | Latest test build | Open branches | Client fixes |', '|---|---|---|---|---|---|---|---|---|' ];
		$notes = [];
		foreach ( $plugins as $p ) {
			foreach ( $p['editions'] as $r ) {
				$rel  = $r['released']['version'] ?? '—';
				$un   = ! isset( $r['unreleased'] ) ? '—' : ( $r['unreleased']['since'] === 'first release pending' ? 'first release pending' : "{$r['unreleased']['commits']} ({$r['unreleased']['user_facing']} user-facing)" );
				$tb   = $r['latest_test_build'] ? $r['latest_test_build']['file'] . ( ! empty( $r['latest_test_build']['from_other_branch'] ) ? " (from {$r['latest_test_build']['branch']})" : ( ! empty( $r['latest_test_build']['commits_since'] ) ? " (+{$r['latest_test_build']['commits_since']} since)" : '' ) ) : '—';
				$hot  = array_column( $r['hotfixes'] ?? [], 'branch' );
				$ob   = array_map( static fn( $b ) => in_array( $b, $hot, true ) ? "$b (hotfix)" : $b, $r['open_branches'] ?? [] );
				$o[]  = "| {$p['plugin']} | {$r['edition']} | {$r['target']} | $rel | " . ( $r['dev_version'] ?? '?' ) . " | $un | $tb | " . ( implode( ', ', $ob ) ?: '—' ) . ' | ' . count( $r['support_unreleased'] ?? [] ) . ' |';
				if ( ! empty( $r['client_builds'] ) ) {
					$top     = array_reduce( array_column( $r['client_builds'], 'build_version' ), static fn( $m, $v ) => $m === null || version_compare( $v, $m, '>' ) ? $v : $m );
					$notes[] = "- {$p['plugin']}/{$r['edition']}: client builds out: " . implode( ', ', array_map( static fn( $c ) => "{$c['ticket']} {$c['build_version']}", $r['client_builds'] ) ) . ". The next release must be above $top and contain these fixes.";
				}
				foreach ( $r['problems'] as $pr ) {
					$notes[] = "- {$p['plugin']}/{$r['edition']}: $pr";
				}
				foreach ( $r['support_unreleased'] ?? [] as $s ) {
					$notes[] = "- {$p['plugin']}/{$r['edition']}: unreleased client fix $s";
				}
			}
			foreach ( $p['problems'] as $pr ) {
				$notes[] = "- {$p['plugin']}: $pr";
			}
			foreach ( $p['unported'] as $u ) {
				$notes[] = "- {$p['plugin']}: {$u['count']} fix(es) in {$u['from']} not in {$u['to']} ({$u['window']}), e.g. " . implode( '; ', $u['examples'] );
			}
		}
		foreach ( $unconfigured as $u ) {
			$notes[] = "- $u: not configured (run yayinit in that repo)";
		}
		return implode( "\n", $o ) . "\n" . ( $notes ? "\n**Needs attention**\n\n" . implode( "\n", $notes ) . "\n" : "\nNothing needs attention.\n" );
	}
}

function cmd_status( array $pos ): int {
	$out          = [];
	$unconfigured = [];
	$ports        = ! flag( 'no-ports' );
	if ( flag( 'all' ) ) {
		$reg = Registry::load();
		foreach ( $reg->data['plugins'] as $key => $entry ) {
			Ctx::$opt['repo'] = $entry['repo'];
			try {
				$cfg = Config::load();
				if ( flag( 'fetch' ) ) {
					run( [ 'git', '-C', $cfg->repo, 'fetch', '--quiet', $cfg->settings()['remote'] ] );
				}
				foreach ( $cfg->plugins() as $p ) {
					if ( $p === $key ) {
						$out[] = Status::plugin( $cfg, $p, $ports );
					}
				}
			} catch ( Fail $f ) {
				$unconfigured[] = "$key ({$entry['repo']}): " . $f->getMessage();
			}
		}
		foreach ( opt_list( 'known' ) as $k ) {
			if ( ! isset( $reg->data['plugins'][ $k ] ) ) {
				$unconfigured[] = $k;
			}
		}
	} else {
		$cfg = Config::load();
		if ( flag( 'fetch' ) ) {
			run( [ 'git', '-C', $cfg->repo, 'fetch', '--quiet', $cfg->settings()['remote'] ] );
		}
		[ $plugin, $eds ] = $cfg->resolve( opt( 'plugin' ), opt( 'edition' ), true );
		$p = Status::plugin( $cfg, $plugin, $ports );
		$p['editions'] = array_intersect_key( $p['editions'], array_flip( $eds ) );
		$out[] = $p;
	}
	if ( flag( 'json' ) ) {
		out_json( [ 'plugins' => $out, 'unconfigured' => $unconfigured ] );
	} else {
		echo Status::markdown( $out, $unconfigured );
	}
	return 0;
}
