<?php
/**
 * The committed support log, support/<plugin>-<edition>.md on that edition's dev branch, and the
 * cross-edition port scan.
 *
 * The log has two tables: "Fixes" (one row per customer patch build: status unreleased until the
 * release that includes it is finalized) and "Port decisions" (a sibling's fix this edition will
 * not take, so yaystatus stops listing it). Only `yr support …` writes it.
 */

declare(strict_types=1);

namespace YR;

final class SupportLog {
	public const FIX_COLS  = [ 'ticket', 'date', 'customer_version', 'fix', 'support_commit', 'dev_commit', 'status', 'released_in' ];
	public const PORT_COLS = [ 'commit', 'from', 'subject', 'decision', 'reason', 'date' ];

	public static function read( Config $cfg, array $e, string $ref ): ?string {
		return git_show( $cfg->repo, $ref, $e['support_log'] );
	}

	/** Parse one table (under "## Fixes" or "## Port decisions"). */
	public static function table( ?string $md, string $section, array $cols ): array {
		if ( $md === null ) {
			return [];
		}
		$rows = [];
		$in   = false;
		foreach ( explode( "\n", $md ) as $l ) {
			if ( preg_match( '/^##\s+(.+)$/', $l, $m ) ) {
				$in = strcasecmp( trim( $m[1] ), $section ) === 0;
				continue;
			}
			if ( ! $in || ! preg_match( '/^\|(.*)\|\s*$/', $l, $m ) ) {
				continue;
			}
			$cells = array_map( static fn( $c ) => str_replace( '\|', '|', trim( $c ) ), preg_split( '/(?<!\\\\)\|/', $m[1] ) );
			if ( count( $cells ) !== count( $cols ) || preg_match( '/^-+$/', $cells[0] ) || strcasecmp( $cells[0], ucfirst( $cols[0] ) ) === 0 ) {
				continue;
			}
			$rows[] = array_combine( $cols, $cells );
		}
		return $rows;
	}

	public static function rows( ?string $md, ?string $status = null ): array {
		$rows = self::table( $md, 'Fixes', self::FIX_COLS );
		return $status ? array_values( array_filter( $rows, static fn( $r ) => strcasecmp( $r['status'], $status ) === 0 ) ) : $rows;
	}

	public static function ports( ?string $md ): array {
		return self::table( $md, 'Port decisions', self::PORT_COLS );
	}

	private static function cell( string $s ): string {
		return str_replace( [ '|', "\n" ], [ '\|', ' ' ], trim( $s ) );
	}

	/** Rewrite one table in the file content, keeping everything else. */
	public static function put_table( string $md, string $section, array $cols, array $rows ): string {
		$headers = [
			'ticket' => 'Ticket', 'date' => 'Date', 'customer_version' => 'Customer runs', 'fix' => 'Fix',
			'support_commit' => 'Support commit', 'dev_commit' => 'Dev commit', 'status' => 'Status', 'released_in' => 'Released in',
			'commit' => 'Commit', 'from' => 'From edition', 'subject' => 'Subject', 'decision' => 'Decision', 'reason' => 'Reason',
		];
		$tbl   = [ '| ' . implode( ' | ', array_map( static fn( $c ) => $headers[ $c ], $cols ) ) . ' |', '|' . str_repeat( '---|', count( $cols ) ) ];
		foreach ( $rows as $r ) {
			$tbl[] = '| ' . implode( ' | ', array_map( static fn( $c ) => self::cell( (string) ( $r[ $c ] ?? '' ) ), $cols ) ) . ' |';
		}
		$lines = explode( "\n", $md );
		$out   = [];
		$state = 'before';
		foreach ( $lines as $l ) {
			if ( preg_match( '/^##\s+(.+)$/', $l, $m ) ) {
				if ( $state === 'in' ) {
					$state = 'after';
				}
				if ( strcasecmp( trim( $m[1] ), $section ) === 0 ) {
					$out[] = $l;
					$out[] = '';
					array_push( $out, ...$tbl );
					$out[] = '';
					$state = 'in';
					continue;
				}
			}
			if ( $state === 'in' ) {
				if ( preg_match( '/^\|/', $l ) || trim( $l ) === '' ) {
					continue; // old table rows
				}
				$out[] = '';
				$state = 'after';
			}
			$out[] = $l;
		}
		if ( $state === 'before' ) {
			$out[] = '';
			$out[] = "## $section";
			$out[] = '';
			array_push( $out, ...$tbl );
		}
		return rtrim( preg_replace( "/\n{3,}/", "\n\n", implode( "\n", $out ) ), "\n" ) . "\n";
	}

	public static function template( string $plugin, string $edition ): string {
		return fill( (string) file_get_contents( kit_dir() . '/templates/support-log.md' ), [ 'plugin' => $plugin, 'edition' => $edition ] );
	}
}

final class Ports {
	private static function norm( string $s ): string {
		$s = strtolower( preg_replace( '/\s*\(#\d+\)\s*$/', '', $s ) );
		$s = preg_replace( '/^#?\s*[a-z]+(\([^)]*\))?!?\s*:\s*/', '', $s );
		return trim( preg_replace( '/[^a-z0-9]+/', ' ', $s ) );
	}

	/**
	 * Fixes on $from's dev branch that $to does not have.
	 *
	 * @return array{from:string,to:string,window:string,unported:array,ported:int,skipped:int}
	 */
	public static function scan( Config $cfg, string $plugin, string $from, string $to, bool $features, int $days ): array {
		$ef = $cfg->edition( $plugin, $from );
		$et = $cfg->edition( $plugin, $to );
		$A  = $ef['dev_branch'];
		$B  = $et['dev_branch'];
		if ( ! ref_exists( $cfg->repo, $A ) || ! ref_exists( $cfg->repo, $B ) ) {
			return [ 'from' => $from, 'to' => $to, 'window' => 'branch missing', 'unported' => [], 'ported' => 0, 'skipped' => 0, 'error' => "missing branch $A or $B" ];
		}
		$base   = Version::base( $cfg, $plugin, $from );
		$range  = $base ? $base['ref'] . '..' . $A : $A;
		$window = $base ? "since {$base['source']}" : "last $days days";
		$args   = [ 'log', '--no-merges', '--format=%H%x1f%h%x1f%aI%x1f%s%x1f%b%x1e', $range ];
		if ( ! $base ) {
			$args[] = "--since=$days.days";
		}
		$raw = git( $cfg->repo, $args );
		$cands = [];
		foreach ( array_filter( explode( "\x1e", $raw ), static fn( $r ) => trim( $r ) !== '' ) as $rec ) {
			$f = explode( "\x1f", ltrim( $rec, "\n" ) );
			if ( count( $f ) < 5 ) {
				continue;
			}
			$c = Commits::classify( $f[3], $f[4] );
			$want = in_array( $c['type'], [ 'fix', 'security', 'perf' ], true ) || ( $features && in_array( $c['type'], [ 'feat', 'improve' ], true ) );
			if ( $c['internal'] || ! $want ) {
				continue;
			}
			$cands[ $f[0] ] = [ 'sha' => $f[0], 'short' => $f[1], 'date' => substr( $f[2], 0, 10 ), 'subject' => $f[3], 'type' => $c['type'] ];
		}
		if ( ! $cands ) {
			return [ 'from' => $from, 'to' => $to, 'window' => $window, 'unported' => [], 'ported' => 0, 'skipped' => 0 ];
		}
		$oldest = min( array_column( $cands, 'date' ) );
		// What B has: cherry-pick trailers, patch-equivalent commits, and matching subjects.
		$braw  = git( $cfg->repo, [ 'log', '--no-merges', '--format=%s%x1f%b%x1e', '--since=' . date( 'Y-m-d', strtotime( "$oldest -30 days" ) ), $B ] );
		$picked = [];
		$subjects = [];
		foreach ( array_filter( explode( "\x1e", $braw ), static fn( $r ) => trim( $r ) !== '' ) as $rec ) {
			[ $s, $b ] = array_pad( explode( "\x1f", ltrim( $rec, "\n" ) ), 2, '' );
			$subjects[ self::norm( $s ) ] = true;
			if ( preg_match_all( '/cherry picked from commit ([0-9a-f]{7,40})/', $b, $m ) ) {
				foreach ( $m[1] as $sha ) {
					$picked[] = $sha;
				}
			}
		}
		$equiv = [];
		$cherry = git_try( $cfg->repo, array_merge( [ 'cherry', $B, $A ], $base ? [ $base['ref'] ] : [] ) ) ?? '';
		foreach ( explode( "\n", $cherry ) as $l ) {
			if ( preg_match( '/^- ([0-9a-f]{40})/', $l, $m ) ) {
				$equiv[ $m[1] ] = true;
			}
		}
		$decisions = [];
		foreach ( SupportLog::ports( SupportLog::read( $cfg, $et, $B ) ) as $d ) {
			$decisions[] = $d;
		}
		$unported = [];
		$ported   = 0;
		$skipped  = 0;
		foreach ( $cands as $sha => $c ) {
			$is_picked = false;
			foreach ( $picked as $p ) {
				if ( strncmp( $sha, $p, strlen( $p ) ) === 0 ) {
					$is_picked = true;
					break;
				}
			}
			if ( $is_picked || isset( $equiv[ $sha ] ) || isset( $subjects[ self::norm( $c['subject'] ) ] ) ) {
				$ported++;
				continue;
			}
			foreach ( $decisions as $d ) {
				if ( $d['commit'] !== '' && strncmp( $sha, $d['commit'], strlen( $d['commit'] ) ) === 0 ) {
					$skipped++;
					continue 2;
				}
			}
			$unported[] = $c;
		}
		return [ 'from' => $from, 'to' => $to, 'window' => $window, 'unported' => $unported, 'ported' => $ported, 'skipped' => $skipped ];
	}

	/** Pairs to scan: the configured propagation flow, else every sibling on another branch. */
	public static function pairs( Config $cfg, string $plugin, ?string $only_from ): array {
		$p     = $cfg->plugin( $plugin );
		$flow  = $p['propagation']['flow'] ?? null;
		$pairs = [];
		if ( $flow ) {
			foreach ( $flow as $pair ) {
				$pairs[] = [ $pair[0], $pair[1] ];
			}
		} else {
			$eds = array_keys( $p['editions'] );
			foreach ( $eds as $a ) {
				foreach ( $eds as $b ) {
					if ( $a !== $b && $cfg->edition( $plugin, $a )['branch_group'] !== $cfg->edition( $plugin, $b )['branch_group'] ) {
						$pairs[] = [ $a, $b ];
					}
				}
			}
		}
		if ( $only_from ) {
			$pairs = array_values( array_filter( $pairs, static fn( $x ) => $x[0] === $only_from ) );
		}
		return $pairs;
	}
}

function cmd_support( array $pos ): int {
	$sub = $pos[1] ?? 'list';
	$cfg = Config::load();
	[ $plugin, $eds ] = $cfg->resolve( opt( 'plugin' ), opt( 'edition' ), $sub === 'list' );

	if ( $sub === 'list' ) {
		$res = [];
		foreach ( $eds as $k ) {
			$e         = $cfg->edition( $plugin, $k );
			$md        = SupportLog::read( $cfg, $e, opt( 'ref', $e['dev_branch'] ) );
			$res[ $k ] = [ 'file' => $e['support_log'], 'exists' => $md !== null, 'fixes' => SupportLog::rows( $md, opt( 'status' ) ), 'port_decisions' => SupportLog::ports( $md ) ];
		}
		out_json( $res );
		return 0;
	}

	$e    = $cfg->edition( $plugin, $eds[0] );
	$tree = expand_home( req( 'tree' ) );
	$file = "$tree/{$e['support_log']}";
	$md   = is_file( $file ) ? (string) file_get_contents( $file ) : SupportLog::template( $plugin, $e['key'] );

	if ( $sub === 'add' ) {
		$rows   = SupportLog::rows( $md );
		$rows[] = [
			'ticket' => req( 'ticket' ), 'date' => opt( 'date', gmdate( 'Y-m-d' ) ), 'customer_version' => req( 'customer-version' ),
			'fix' => req( 'fix' ), 'support_commit' => req( 'support-commit' ), 'dev_commit' => opt( 'dev-commit', 'not yet' ),
			'status' => 'unreleased', 'released_in' => '',
		];
		$md = SupportLog::put_table( $md, 'Fixes', SupportLog::FIX_COLS, $rows );
	} elseif ( $sub === 'set-dev-commit' ) {
		$rows = SupportLog::rows( $md );
		$hit  = false;
		foreach ( $rows as &$r ) {
			if ( $r['ticket'] === req( 'ticket' ) ) {
				$r['dev_commit'] = req( 'dev-commit' );
				$hit             = true;
			}
		}
		unset( $r );
		if ( ! $hit ) {
			throw new Fail( 'no row for ticket ' . opt( 'ticket' ) );
		}
		$md = SupportLog::put_table( $md, 'Fixes', SupportLog::FIX_COLS, $rows );
	} elseif ( $sub === 'mark-released' ) {
		$version = req( 'version' );
		$only    = opt_list( 'tickets' );
		$rows    = SupportLog::rows( $md );
		$n       = 0;
		foreach ( $rows as &$r ) {
			if ( strcasecmp( $r['status'], 'unreleased' ) === 0 && ( ! $only || in_array( $r['ticket'], $only, true ) ) ) {
				$r['status']      = 'released';
				$r['released_in'] = $version;
				$n++;
			}
		}
		unset( $r );
		$md = SupportLog::put_table( $md, 'Fixes', SupportLog::FIX_COLS, $rows );
		say( "$n fix(es) marked released in $version" );
	} elseif ( $sub === 'port-decision' ) {
		$rows   = SupportLog::ports( $md );
		$rows[] = [ 'commit' => req( 'commit' ), 'from' => req( 'from' ), 'subject' => opt( 'subject', '' ), 'decision' => opt( 'decision', 'skip' ), 'reason' => req( 'reason' ), 'date' => gmdate( 'Y-m-d' ) ];
		$md     = SupportLog::put_table( $md, 'Port decisions', SupportLog::PORT_COLS, $rows );
	} else {
		throw new Fail( "unknown: support $sub (list|add|set-dev-commit|mark-released|port-decision)", EXIT_USAGE );
	}
	write_file( $file, $md, "support log $sub" );
	out_json( [ 'file' => $e['support_log'], 'fixes' => SupportLog::rows( $md ), 'port_decisions' => SupportLog::ports( $md ), 'dry_run' => Ctx::$dry ] );
	return 0;
}

function cmd_ports( array $pos ): int {
	$cfg = Config::load();
	[ $plugin ] = $cfg->resolve( opt( 'plugin' ), null, true );
	$res = [];
	foreach ( Ports::pairs( $cfg, $plugin, opt( 'edition' ) ) as [ $a, $b ] ) {
		$res[] = Ports::scan( $cfg, $plugin, $a, $b, flag( 'features' ), (int) opt( 'days', '60' ) );
	}
	out_json( [ 'plugin' => $plugin, 'method' => $cfg->plugin( $plugin )['propagation']['method'] ?? 'cherry-pick', 'pairs' => $res ] );
	return 0;
}
