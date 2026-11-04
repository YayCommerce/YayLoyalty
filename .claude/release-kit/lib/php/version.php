<?php
/**
 * Version numbers: read every configured location (from a git ref or a working tree), check they
 * agree, find an edition's latest release tag, propose a bump from the commits since it, and
 * write a new version into every location.
 *
 * Versions may have 3 or 4 parts (3.5.7.2). A bump always yields 3 parts: patch on 3.5.7.2 → 3.5.8.
 *
 * Client versions: a zip handed to clients before (or instead of) a release is labelled
 * `<target>-hotfix.<n>`, where target is the version the fix will be released as (3.6.3-hotfix.2).
 * PHP's version_compare, which WordPress, the EDD updater and the Woo updater use, orders
 *   3.6.2 < 3.6.3-hotfix.1 < 3.6.3-hotfix.2 < 3.6.3 < 3.7.0
 * so a client build replaces the buggy live version, a later client build replaces an earlier
 * one, and the real release replaces every client build. A suffix on the *live* version would
 * sort below it (3.6.2-hotfix.1 < 3.6.2), which is why the target is always above the release.
 * Client versions live only inside zips (written into the built zip by `yr builds set-version`), never in git.
 */

declare(strict_types=1);

namespace YR;

final class Version {
	public static function valid( string $v ): bool {
		return (bool) preg_match( '/^\d+\.\d+(\.\d+){0,2}$/', $v );
	}

	/** A client build version: <release version>-hotfix.<n>. */
	public static function valid_client( string $v ): bool {
		return (bool) preg_match( '/^\d+\.\d+(\.\d+){0,2}-hotfix\.\d+$/', $v );
	}

	/** [target, n] of a client version, or null. */
	public static function client_parts( string $v ): ?array {
		return preg_match( '/^(\d+\.\d+(?:\.\d+){0,2})-hotfix\.(\d+)$/', $v, $m ) ? [ $m[1], (int) $m[2] ] : null;
	}

	/** @return int[] */
	public static function parts( string $v ): array {
		return array_map( 'intval', explode( '.', $v ) );
	}

	public static function cmp( string $a, string $b ): int {
		return version_compare( $a, $b );
	}

	public static function bump( string $v, string $kind ): string {
		$p = self::parts( $v ) + [ 0, 0, 0 ];
		switch ( $kind ) {
			case 'major':
				return ( $p[0] + 1 ) . '.0.0';
			case 'minor':
				return $p[0] . '.' . ( $p[1] + 1 ) . '.0';
			case 'patch':
				return $p[0] . '.' . $p[1] . '.' . ( $p[2] + 1 );
		}
		throw new Fail( "unknown bump '$kind' (major|minor|patch)", EXIT_USAGE );
	}

	/**
	 * Read each version location. $ref reads committed content; $tree reads a working directory.
	 *
	 * @return array<int,array{path:string,value:?string,optional:bool}>
	 */
	public static function read( Config $cfg, array $e, ?string $ref, ?string $tree ): array {
		$out = [];
		foreach ( $e['version_files'] as $vf ) {
			$content = $tree !== null
				? ( is_file( "$tree/{$vf['path']}" ) ? (string) file_get_contents( "$tree/{$vf['path']}" ) : null )
				: git_show( $cfg->repo, (string) $ref, $vf['path'] );
			$value = null;
			if ( $content !== null && preg_match( rx( $vf['pattern'] ), $content, $m, PREG_OFFSET_CAPTURE ) ) {
				$value = trim( $m['v'][0] );
				// Patterns written for plain versions ([0-9][0-9.]*) stop before a client suffix; read it too.
				if ( preg_match( '/\G-hotfix\.\d+/', $content, $sx, 0, $m['v'][1] + strlen( $m['v'][0] ) ) ) {
					$value .= $sx[0];
				}
			}
			$out[] = [ 'path' => $vf['path'], 'pattern' => $vf['pattern'], 'value' => $value, 'optional' => ! empty( $vf['optional'] ), 'present' => $content !== null ];
		}
		return $out;
	}

	/** @return array{version:?string,consistent:bool,problems:string[],locations:array} */
	public static function check( array $locations ): array {
		$vals     = [];
		$problems = [];
		foreach ( $locations as $l ) {
			if ( $l['value'] === null ) {
				if ( ! $l['optional'] || $l['present'] ) {
					$problems[] = $l['present'] ? "{$l['path']}: version pattern not found" : "{$l['path']}: file missing";
				}
				continue;
			}
			$vals[ $l['path'] . ' ' . $l['pattern'] ] = $l['value'];
		}
		$uniq = array_values( array_unique( $vals ) );
		if ( count( $uniq ) > 1 ) {
			foreach ( $vals as $where => $v ) {
				$problems[] = 'mismatch: ' . strtok( $where, ' ' ) . " = $v";
			}
		}
		return [ 'version' => $uniq[0] ?? null, 'consistent' => ! $problems, 'problems' => $problems, 'locations' => $locations ];
	}

	/** Latest release tag of an edition: [tag, version, sha] or null. */
	public static function latest_tag( Config $cfg, string $plugin, string $edition ): ?array {
		$prefix = $cfg->tag_prefix( $plugin, $edition );
		$raw    = git_try( $cfg->repo, [ 'tag', '-l', $prefix . '*' ] ) ?? '';
		$best   = null;
		foreach ( array_filter( explode( "\n", $raw ) ) as $tag ) {
			$v = substr( $tag, strlen( $prefix ) );
			if ( ! self::valid( $v ) ) {
				continue;
			}
			if ( $best === null || self::cmp( $v, $best[1] ) > 0 ) {
				$best = [ $tag, $v ];
			}
		}
		if ( ! $best ) {
			return null;
		}
		$best[] = git( $cfg->repo, [ 'rev-list', '-n', '1', $best[0] ] );
		return $best;
	}

	/**
	 * The commit releases are measured from: the latest tag, else the configured baseline.
	 *
	 * @return array{ref:string,version:string,source:string}|null
	 */
	public static function base( Config $cfg, string $plugin, string $edition ): ?array {
		$t = self::latest_tag( $cfg, $plugin, $edition );
		if ( $t ) {
			return [ 'ref' => $t[2], 'version' => $t[1], 'source' => "tag {$t[0]}", 'tag' => $t[0] ];
		}
		$b = $cfg->edition( $plugin, $edition )['baseline'] ?? null;
		if ( ! empty( $b['first_release'] ) && ! empty( $b['confirmed'] ) ) {
			// Never released anywhere: the first release keeps the dev version and gets an "Initial release" changelog.
			return [ 'ref' => null, 'version' => null, 'source' => 'first release (never released before)', 'tag' => null, 'first_release' => true ];
		}
		if ( ! empty( $b['sha'] ) && ! empty( $b['confirmed'] ) && ref_exists( $cfg->repo, $b['sha'] ) ) {
			return [ 'ref' => $b['sha'], 'version' => (string) $b['version'], 'source' => 'confirmed baseline (tag not created yet)', 'tag' => null ];
		}
		return null;
	}

	/** Replace the version in every location of a working tree. Returns the changed files. */
	public static function write( array $e, string $tree, string $new ): array {
		$changed = [];
		$by_file = [];
		foreach ( $e['version_files'] as $vf ) {
			$by_file[ $vf['path'] ][] = $vf;
		}
		foreach ( $by_file as $path => $vfs ) {
			$file = "$tree/$path";
			if ( ! is_file( $file ) ) {
				$opt = array_filter( $vfs, static fn( $v ) => ! empty( $v['optional'] ) );
				if ( count( $opt ) === count( $vfs ) ) {
					continue;
				}
				throw new Fail( "version file missing: $path" );
			}
			$src = (string) file_get_contents( $file );
			$dst = $src;
			foreach ( $vfs as $vf ) {
				$count = 0;
				$dst   = preg_replace_callback(
					rx( $vf['pattern'] ),
					static function ( $m ) use ( $new ) {
						$full  = $m[0][0];
						$start = $m['v'][1] - $m[0][1];
						return substr( $full, 0, $start ) . $new . substr( $full, $start + strlen( $m['v'][0] ) );
					},
					$dst,
					empty( $vf['all'] ) ? 1 : -1,
					$count,
					PREG_OFFSET_CAPTURE
				);
				if ( $count === 0 && empty( $vf['optional'] ) ) {
					throw new Fail( "version pattern not found in $path: {$vf['pattern']}" );
				}
			}
			if ( $dst !== $src ) {
				write_file( $file, $dst, "version → $new" );
				$changed[] = $path;
			}
		}
		return $changed;
	}
}

function cmd_version( array $pos ): int {
	$sub = $pos[1] ?? 'get';
	$cfg = Config::load();
	[ $plugin, $eds ] = $cfg->resolve( opt( 'plugin' ), opt( 'edition' ), $sub === 'get' || $sub === 'check' );

	if ( $sub === 'get' || $sub === 'check' ) {
		$res = [];
		$bad = false;
		foreach ( $eds as $k ) {
			$e   = $cfg->edition( $plugin, $k );
			$ref = opt( 'ref', $e['dev_branch'] );
			$tree = opt( 'tree' );
			$chk = Version::check( Version::read( $cfg, $e, $tree ? null : $ref, $tree ? expand_home( $tree ) : null ) );
			$res[ $k ] = [ 'ref' => $tree ? "tree:$tree" : $ref ] + $chk;
			$bad = $bad || ! $chk['consistent'];
		}
		if ( $cfg->plugin( $plugin )['version_policy'] === 'shared' && count( $res ) > 1 ) {
			$vs = array_unique( array_filter( array_column( $res, 'version' ) ) );
			if ( count( $vs ) > 1 ) {
				$res['_shared_policy'] = [ 'problems' => [ 'shared version policy, but editions differ: ' . implode( ', ', array_map( static fn( $k, $r ) => "$k={$r['version']}", array_keys( $res ), $res ) ) ] ];
				$bad = true;
			}
		}
		out_json( $res );
		return ( $sub === 'check' && $bad ) ? EXIT_FAIL : 0;
	}

	if ( $sub === 'base' ) {
		// What is live now for an edition: its latest tag, else the confirmed baseline.
		$k    = $eds[0];
		$base = Version::base( $cfg, $plugin, $k );
		if ( ! $base ) {
			throw new Fail( "$plugin/$k has no release tag and no confirmed baseline. Run the yayinit skill first.", EXIT_NOT_CONFIG );
		}
		out_json( [ 'plugin' => $plugin, 'edition' => $k ] + $base );
		return 0;
	}

	if ( $sub === 'propose' ) {
		$k    = $eds[0];
		$e    = $cfg->edition( $plugin, $k );
		$head = opt( 'ref', $e['dev_branch'] );
		$base = Version::base( $cfg, $plugin, $k );
		if ( ! $base ) {
			throw new Fail( "$plugin/$k has no release tag and no confirmed baseline. Run the yayinit skill to set one; release skills skip it until then.", EXIT_NOT_CONFIG );
		}
		$current  = Version::check( Version::read( $cfg, $e, $head, null ) )['version'];
		if ( ! empty( $base['first_release'] ) ) {
			out_json( [
				'plugin' => $plugin, 'edition' => $k, 'base' => $base, 'head' => $head, 'dev_version' => $current,
				'bump' => 'none', 'proposed' => $current, 'reasons' => [ 'first release: ships the version the dev branch already has' ],
				'uncertain' => [], 'notes' => [ 'first release: the changelog is a short "Initial release" entry, not a commit list' ], 'siblings' => [],
				'commit_count' => (int) git( $cfg->repo, [ 'rev-list', '--count', $head ] ), 'from_version' => null,
			] );
			return 0;
		}
		$commits  = Commits::log( $cfg->repo, $base['ref'] . '..' . $head );
		$proposal = Commits::propose_bump( $commits );
		$from     = Version::cmp( (string) $current, $base['version'] ) > 0 ? (string) $current : $base['version'];
		$next     = $proposal['bump'] === 'none' ? null : Version::bump( $base['version'], $proposal['bump'] );
		$notes    = [];
		if ( $next && Version::cmp( (string) $current, $base['version'] ) > 0 ) {
			$notes[] = "the dev branch already says $current (released: {$base['version']}); proposal counts from the release, so $next" . ( Version::cmp( $next, (string) $current ) < 0 ? " is below $current: pick $current or higher" : '' );
		}
		// Client builds out (support log): the release must be above every one of them.
		$clients = array_values( array_filter( array_column( SupportLog::rows( SupportLog::read( $cfg, $e, $e['dev_branch'] ), 'unreleased' ), 'build_version' ), 'strlen' ) );
		foreach ( $clients as $cv ) {
			if ( $next && Version::cmp( $next, $cv ) <= 0 ) {
				$notes[] = "a client received $cv: $next would never reach them; release above $cv";
			}
		}
		$siblings = [];
		if ( $cfg->plugin( $plugin )['version_policy'] === 'shared' ) {
			foreach ( $cfg->siblings( $plugin, $k ) as $s ) {
				$sb  = Version::base( $cfg, $plugin, $s );
				$se  = $cfg->edition( $plugin, $s );
				$sv  = Version::check( Version::read( $cfg, $se, $se['dev_branch'], null ) )['version'];
				$siblings[ $s ] = [
					'released'   => $sb['version'] ?? null,
					'dev'        => $sv,
					'would_be'   => $next,
					'left_behind' => $next !== null && ( $sb === null || Version::cmp( $sb['version'], $next ) < 0 ),
					'no_baseline' => $sb === null,
				];
			}
		}
		out_json( [
			'plugin' => $plugin, 'edition' => $k, 'base' => $base, 'head' => $head, 'dev_version' => $current,
			'bump' => $proposal['bump'], 'proposed' => $next, 'reasons' => $proposal['reasons'],
			'uncertain' => $proposal['uncertain'], 'notes' => $notes, 'siblings' => $siblings,
			'commit_count' => count( $commits ), 'from_version' => $from, 'client_builds' => $clients,
		] );
		return 0;
	}

	if ( $sub === 'bump' ) {
		$to = req( 'to' );
		// --client: a client version (X.Y.Z-hotfix.N); only for scratch trees, never a branch.
		if ( ! Version::valid( $to ) && ! ( flag( 'client' ) && Version::valid_client( $to ) ) ) {
			throw new Fail( "not a version: $to" . ( Version::valid_client( $to ) ? ' (client versions go only into built zips: yr builds set-version)' : '' ), EXIT_USAGE );
		}
		$tree = expand_home( req( 'tree' ) );
		$changed = [];
		foreach ( $eds as $k ) {
			$changed = array_merge( $changed, Version::write( $cfg->edition( $plugin, $k ), $tree, $to ) );
		}
		$after = [];
		if ( ! Ctx::$dry ) {
			foreach ( $eds as $k ) {
				$after[ $k ] = Version::check( Version::read( $cfg, $cfg->edition( $plugin, $k ), null, $tree ) );
				if ( $after[ $k ]['version'] !== $to || ! $after[ $k ]['consistent'] ) {
					throw new Fail( "after the bump $k reads " . json_encode( $after[ $k ]['problems'] ?: $after[ $k ]['version'] ) );
				}
			}
		}
		out_json( [ 'to' => $to, 'changed' => array_values( array_unique( $changed ) ), 'verified' => $after, 'dry_run' => Ctx::$dry ] );
		return 0;
	}

	throw new Fail( "unknown: version $sub (get|check|base|propose|bump)", EXIT_USAGE );
}
