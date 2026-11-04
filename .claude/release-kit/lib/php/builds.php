<?php
/**
 * Build artefacts in dist/<plugin>/<edition>/: zip names and numbers, the build-info.json written
 * into each zip, the BUILD.json sidecar next to it, and zip inspection.
 *
 * Names (the zip's internal folder always stays the plugin's real slug):
 *   test     <stem>-X.Y.Z-test.<n>-<shortsha>.zip
 *   release  <stem>-X.Y.Z.zip, resubmissions <stem>-X.Y.Z-r<n>.zip
 *   hotfix   <stem>-T-hotfix.<n>-<shortsha>.zip              client build from a hotfix/release branch
 *   support  <stem>-T-hotfix.<n>-support-<ticket>-<shortsha>.zip   client build from a support branch
 *
 * Client builds (hotfix, support) carry the version T-hotfix.<n> inside the zip, where T is the
 * release the fix is headed for: a hotfix branch's own version, or for a support build the patch
 * after the live version. <n> counts up per T across dist/ and the committed support log, so two
 * client builds never share a version. See Version (lib/php/version.php) for why this ordering
 * makes every later client build and the real release an update.
 */

declare(strict_types=1);

namespace YR;

final class Builds {
	/** Every BUILD.json in an edition's dist folder, newest first. */
	public static function all( string $dist_dir ): array {
		$out = [];
		foreach ( glob( $dist_dir . '/*.BUILD.json' ) ?: [] as $f ) {
			$b = json_decode( (string) file_get_contents( $f ), true );
			if ( is_array( $b ) ) {
				$b['_file'] = $f;
				$out[]      = $b;
			}
		}
		usort( $out, static fn( $a, $b ) => strcmp( (string) ( $b['date'] ?? '' ), (string) ( $a['date'] ?? '' ) ) );
		return $out;
	}

	public static function latest( string $dist_dir, ?string $type = null ): ?array {
		foreach ( self::all( $dist_dir ) as $b ) {
			if ( $type === null || ( $b['build_type'] ?? '' ) === $type ) {
				return $b;
			}
		}
		return null;
	}

	/**
	 * Version for a client build: [target, n, "target-hotfix.n"].
	 * hotfix: target = the X.Y.Z of a release branch name (release/<stem>-X.Y.Z), else the commit's
	 *   version; it must be above the live one.
	 * support: target = the patch after the live version (the customer may run an older one).
	 */
	public static function client_version( Config $cfg, string $plugin, string $edition, string $type, string $commit_version, ?string $ref = null ): array {
		$base = Version::base( $cfg, $plugin, $edition );
		if ( ! $base || ! empty( $base['first_release'] ) ) {
			throw new Fail( "$plugin/$edition has no live version yet (no tag or confirmed baseline); a client build has nothing to replace", EXIT_NOT_CONFIG );
		}
		if ( $type === 'hotfix' ) {
			$target = $ref !== null && preg_match( '#^release/.+-(\d+\.\d+(?:\.\d+){0,2})$#', $ref, $m ) ? $m[1] : $commit_version;
			if ( Version::cmp( $target, $base['version'] ) <= 0 ) {
				throw new Fail( "$target is not above the live {$base['version']}. Client builds come from a hotfix or release branch (release/<stem>-X.Y.Z, start one with: yr hotfix start)", EXIT_USAGE );
			}
		} else {
			$target = Version::bump( $base['version'], 'patch' );
		}
		$n = 0;
		foreach ( self::all( $cfg->dist_dir( $plugin, $edition ) ) as $b ) {
			$cp = Version::client_parts( (string) ( $b['version'] ?? '' ) );
			if ( $cp && $cp[0] === $target ) {
				$n = max( $n, $cp[1] );
			}
		}
		$e = $cfg->edition( $plugin, $edition );
		foreach ( SupportLog::rows( SupportLog::read( $cfg, $e, $e['dev_branch'] ) ) as $r ) {
			$cp = Version::client_parts( (string) ( $r['build_version'] ?? '' ) );
			if ( $cp && $cp[0] === $target ) {
				$n = max( $n, $cp[1] );
			}
		}
		$n++;
		return [ $target, $n, "$target-hotfix.$n" ];
	}

	/** Next number and file name for a build. */
	public static function next( Config $cfg, string $plugin, string $edition, string $type, string $version, string $short, ?string $ticket, ?string $ref = null ): array {
		$dir  = $cfg->dist_dir( $plugin, $edition );
		$stem = $cfg->stem( $plugin, $edition );
		$all  = self::all( $dir );
		switch ( $type ) {
			case 'hotfix':
				[ $target, $n, $cv ] = self::client_version( $cfg, $plugin, $edition, 'hotfix', $version, $ref );
				return [ 'number' => $n, 'resubmission' => null, 'version' => $cv, 'source_version' => $version, 'target' => $target, 'file' => "$stem-$cv-$short.zip" ];
			case 'test':
				$n = 0;
				foreach ( $all as $b ) {
					if ( ( $b['build_type'] ?? '' ) === 'test' && ( $b['version'] ?? '' ) === $version ) {
						$n = max( $n, (int) $b['build_number'] );
					}
				}
				$n++;
				return [ 'number' => $n, 'resubmission' => null, 'file' => "$stem-$version-test.$n-$short.zip" ];
			case 'release':
				// A release zip counts as a submission once the manager has it (`builds mark-submitted`).
				// Until then a rebuild of the same version replaces the candidate (moved to superseded/);
				// after it, every rebuild is a resubmission: -r1, -r2, …
				$r         = 0;
				$candidate = null;
				foreach ( $all as $b ) {
					if ( ( $b['build_type'] ?? '' ) !== 'release' || ( $b['version'] ?? '' ) !== $version ) {
						continue;
					}
					if ( ! empty( $b['submitted'] ) ) {
						$r = max( $r, (int) ( $b['resubmission'] ?? 0 ) + 1 );
					}
				}
				$file = $r === 0 ? "$stem-$version.zip" : "$stem-$version-r$r.zip";
				foreach ( $all as $b ) {
					if ( ( $b['zip']['file'] ?? '' ) === $file && empty( $b['submitted'] ) ) {
						$candidate = $b['_file'];
					}
				}
				return [ 'number' => null, 'resubmission' => $r, 'file' => $file, 'unsubmitted_candidate' => $candidate ];
			case 'support':
				if ( ! $ticket ) {
					throw new Fail( 'support builds need --ticket', EXIT_USAGE );
				}
				$t = preg_replace( '/[^A-Za-z0-9._-]+/', '-', $ticket );
				[ $target, $n, $cv ] = self::client_version( $cfg, $plugin, $edition, 'support', $version );
				return [ 'number' => $n, 'resubmission' => null, 'version' => $cv, 'source_version' => $version, 'target' => $target, 'file' => "$stem-$cv-support-$t-$short.zip" ];
		}
		throw new Fail( "unknown build type '$type' (test|release|hotfix|support)", EXIT_USAGE );
	}

	/** Entries, top-level folders and size of a zip. */
	public static function inspect( string $zip ): array {
		$z = new \ZipArchive();
		if ( $z->open( $zip ) !== true ) {
			throw new Fail( "cannot open zip: $zip" );
		}
		$entries = [];
		$roots   = [];
		$usize   = 0;
		for ( $i = 0; $i < $z->numFiles; $i++ ) {
			$st        = $z->statIndex( $i );
			$entries[] = $st['name'];
			$usize    += (int) $st['size'];
			$roots[ explode( '/', $st['name'] )[0] ] = true;
		}
		$z->close();
		return [
			'entries' => $entries,
			'roots'   => array_keys( $roots ),
			'size'    => filesize( $zip ),
			'unpacked_size' => $usize,
			'sha256'  => hash_file( 'sha256', $zip ),
		];
	}

	/** Add <slug>/build-info.json to a zip in place. */
	public static function inject_info( string $zip, string $slug, array $info ): void {
		$z = new \ZipArchive();
		if ( $z->open( $zip ) !== true ) {
			throw new Fail( "cannot open zip: $zip" );
		}
		$z->addFromString( "$slug/build-info.json", json_encode( $info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
		if ( ! $z->close() ) {
			throw new Fail( "cannot write build-info.json into $zip" );
		}
	}

	/**
	 * Write a client version into the version locations inside a built zip (<slug>/<path>), after the
	 * repo's own build ran on the unchanged tree: repo scripts may reject X.Y.Z-hotfix.N.
	 *
	 * @return string[] the zip entries changed
	 */
	public static function set_version( string $zip, array $e, string $version ): array {
		$z = new \ZipArchive();
		if ( $z->open( $zip ) !== true ) {
			throw new Fail( "cannot open zip: $zip" );
		}
		$tmp = sys_get_temp_dir() . '/yr-setver-' . getmypid() . '-' . bin2hex( random_bytes( 3 ) );
		$vfs = [];
		foreach ( $e['version_files'] as $vf ) {
			$raw = $z->getFromName( "{$e['slug']}/{$vf['path']}" );
			if ( $raw === false ) {
				continue; // not shipped (e.g. README.md)
			}
			@mkdir( dirname( "$tmp/{$vf['path']}" ), 0777, true );
			file_put_contents( "$tmp/{$vf['path']}", $raw );
			$vfs[] = $vf;
		}
		if ( ! $vfs ) {
			$z->close();
			throw new Fail( "no version file found inside $zip under {$e['slug']}/" );
		}
		$changed = Version::write( [ 'version_files' => $vfs ] + $e, $tmp, $version );
		foreach ( $changed as $path ) {
			$z->addFromString( "{$e['slug']}/$path", (string) file_get_contents( "$tmp/$path" ) );
		}
		if ( ! $z->close() ) {
			throw new Fail( "cannot write $zip" );
		}
		run( [ 'rm', '-rf', $tmp ] );
		return array_map( static fn( $p ) => "{$e['slug']}/$p", $changed );
	}

	public static function read_info( string $zip, string $slug ): ?array {
		$z = new \ZipArchive();
		if ( $z->open( $zip ) !== true ) {
			return null;
		}
		$raw = $z->getFromName( "$slug/build-info.json" );
		$z->close();
		return $raw === false ? null : json_decode( $raw, true );
	}
}

function cmd_builds( array $pos ): int {
	$sub = $pos[1] ?? 'list';
	$cfg = Config::load();

	if ( $sub === 'next' ) {
		[ $plugin, $eds ] = $cfg->resolve( opt( 'plugin' ), opt( 'edition' ) );
		out_json( Builds::next( $cfg, $plugin, $eds[0], req( 'type' ), req( 'version' ), req( 'short' ), opt( 'ticket' ), opt( 'ref' ) ) );
		return 0;
	}
	if ( $sub === 'list' || $sub === 'latest' ) {
		[ $plugin, $eds ] = $cfg->resolve( opt( 'plugin' ), opt( 'edition' ), true );
		$res = [];
		foreach ( $eds as $k ) {
			$dir       = $cfg->dist_dir( $plugin, $k );
			$res[ $k ] = $sub === 'latest' ? Builds::latest( $dir, opt( 'type' ) ) : Builds::all( $dir );
		}
		out_json( $res );
		return 0;
	}
	if ( $sub === 'finalize' ) {
		// Called by lib/sh/build.sh after the zip passed its gates: inject build-info.json, write BUILD.json.
		[ $plugin, $eds ] = $cfg->resolve( opt( 'plugin' ), opt( 'edition' ) );
		$e      = $cfg->edition( $plugin, $eds[0] );
		$zip    = req( 'zip' );
		$info   = read_json( req( 'info' ) );
		$gates  = opt( 'gates' ) ? read_json( (string) opt( 'gates' ) ) : null;
		$dest   = req( 'dest' );
		if ( file_exists( $dest ) ) {
			throw new Fail( "refusing to overwrite $dest", EXIT_EXISTS );
		}
		if ( Ctx::$dry ) {
			say( "[dry-run] would write $dest and " . preg_replace( '/\.zip$/', '.BUILD.json', $dest ) );
			return 0;
		}
		if ( ! rename( $zip, $dest ) ) {
			throw new Fail( "cannot move $zip to $dest" );
		}
		$z           = Builds::inspect( $dest );
		$build       = $info + [
			'zip'   => [ 'file' => basename( $dest ), 'path' => $dest, 'sha256' => $z['sha256'], 'size' => $z['size'], 'root' => $e['slug'] ],
			'gates' => $gates ? [ 'ok' => $gates['ok'], 'summary' => $gates['summary'], 'file' => basename( (string) opt( 'gates' ) ) ] : null,
		];
		write_json( preg_replace( '/\.zip$/', '.BUILD.json', $dest ), $build );
		out_json( $build );
		return 0;
	}
	if ( $sub === 'info' ) {
		// Assemble the build-info.json content (also the base of BUILD.json).
		[ $plugin, $eds ] = $cfg->resolve( opt( 'plugin' ), opt( 'edition' ) );
		$e      = $cfg->edition( $plugin, $eds[0] );
		$shared = opt( 'shared' ) ? read_json( (string) opt( 'shared' ) ) : [];
		$info   = [
			'schema'        => 1,
			'plugin'        => $plugin,
			'edition'       => $e['key'],
			'label'         => $e['label'] ?? null,
			'target'        => $e['target'],
			'slug'          => $e['slug'],
			'version'       => req( 'version' ),
			'source_version' => opt( 'source-version' ) ?: null,
			'client_build'  => in_array( req( 'type' ), [ 'hotfix', 'support' ], true ),
			'build_type'    => req( 'type' ),
			'build_number'  => opt( 'number' ) !== null && opt( 'number' ) !== '' ? (int) opt( 'number' ) : null,
			'resubmission'  => opt( 'resubmission' ) !== null && opt( 'resubmission' ) !== '' ? (int) opt( 'resubmission' ) : null,
			'ticket'        => opt( 'ticket' ),
			'build_id'      => req( 'build-id' ),
			'git'           => [ 'sha' => req( 'sha' ), 'short' => substr( req( 'sha' ), 0, 8 ), 'branch' => opt( 'branch' ), 'ref' => opt( 'ref' ) ],
			'shared_code'   => $shared,
			'date'          => now_iso(),
			'kit_version'   => kit_version(),
		];
		write_json( req( 'out' ), $info );
		return 0;
	}
	if ( $sub === 'set-version' ) {
		// Client builds: yr builds set-version --zip=<built zip> --version=X.Y.Z-hotfix.N
		$v = req( 'version' );
		if ( ! Version::valid_client( $v ) ) {
			throw new Fail( "set-version is for client versions (X.Y.Z-hotfix.N), got $v", EXIT_USAGE );
		}
		[ $plugin, $eds ] = $cfg->resolve( opt( 'plugin' ), opt( 'edition' ) );
		out_json( [ 'version' => $v, 'changed' => Builds::set_version( req( 'zip' ), $cfg->edition( $plugin, $eds[0] ), $v ) ] );
		return 0;
	}
	if ( $sub === 'inject' ) {
		Builds::inject_info( req( 'zip' ), req( 'slug' ), read_json( req( 'info' ) ) );
		return 0;
	}
	if ( $sub === 'mark-submitted' ) {
		// The manager received this release zip (yayrelease's last step, after the user confirms).
		$bjson = req( 'build-json' );
		$b     = read_json( $bjson );
		if ( ( $b['build_type'] ?? '' ) !== 'release' ) {
			throw new Fail( 'only release builds are submitted', EXIT_USAGE );
		}
		$b['submitted']    = true;
		$b['submitted_at'] = now_iso();
		$b['submitted_to'] = opt( 'to', $b['target'] );
		write_json( $bjson, $b, 'mark submitted' );
		say( ( Ctx::$dry ? '[dry-run] ' : '' ) . "marked {$b['zip']['file']} as handed to the manager; the next rebuild of {$b['version']} will be -r" . ( (int) $b['resubmission'] + 1 ) );
		return 0;
	}
	if ( $sub === 'supersede' ) {
		// Move an unsubmitted release candidate aside before rebuilding the same version.
		$bjson = req( 'build-json' );
		$b     = read_json( $bjson );
		if ( ! empty( $b['submitted'] ) ) {
			throw new Fail( "{$b['zip']['file']} was already handed to the manager; rebuild as a resubmission instead", EXIT_EXISTS );
		}
		$dir = dirname( $bjson ) . '/superseded';
		$ts  = gmdate( 'YmdHis' );
		if ( Ctx::$dry ) {
			say( "[dry-run] would move {$b['zip']['file']} and its BUILD.json to $dir/" );
			return 0;
		}
		@mkdir( $dir, 0777, true );
		rename( $b['zip']['path'], "$dir/$ts-{$b['zip']['file']}" );
		rename( $bjson, "$dir/$ts-" . basename( $bjson ) );
		say( "moved the unsubmitted candidate {$b['zip']['file']} to superseded/ (nothing deleted)" );
		return 0;
	}
	if ( $sub === 'inspect' ) {
		$i = Builds::inspect( req( 'zip' ) );
		if ( ! flag( 'entries' ) ) {
			$i['entries'] = count( $i['entries'] );
		}
		out_json( $i );
		return 0;
	}
	if ( $sub === 'verify' ) {
		// Checksum of a zip against its BUILD.json (yayfinalize step 1).
		$zip   = req( 'zip' );
		$bjson = opt( 'build-json', preg_replace( '/\.zip$/', '.BUILD.json', $zip ) );
		$b     = read_json( (string) $bjson );
		$sha   = hash_file( 'sha256', $zip );
		$ok    = hash_equals( (string) $b['zip']['sha256'], $sha );
		out_json( [ 'zip' => $zip, 'expected' => $b['zip']['sha256'], 'actual' => $sha, 'match' => $ok, 'git_sha' => $b['git']['sha'] ?? null, 'version' => $b['version'] ?? null ] );
		return $ok ? 0 : EXIT_FAIL;
	}
	throw new Fail( "unknown: builds $sub (next|list|latest|info|inject|finalize|mark-submitted|supersede|inspect|verify)", EXIT_USAGE );
}
