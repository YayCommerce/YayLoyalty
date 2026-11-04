<?php
/**
 * Read-only scan of a plugin repo for yayinit: candidate edition branches and what each one
 * looks like (plugin name, version and its locations, licensing adapter, Woo header, build
 * scripts, slugs), tags, ignore rules, shared code, and local WordPress.org SVN checkouts.
 *
 * Everything here is a guess for a person to confirm. It never assumes a branch naming pattern:
 * it inspects each branch's files.
 */

declare(strict_types=1);

namespace YR;

final class Detect {
	/** Branches that look like features or personal work are listed but not inspected. */
	private static function looks_like_feature( string $b ): bool {
		$short = preg_replace( '#^origin/#', '', $b );
		if ( preg_match( '#^(backup|archive|tmp|test)[/-]#i', $short ) ) {
			return true;
		}
		if ( preg_match( '#(^|/)(feat|feature|fix|hotfix|bugfix|improve|refactor|chore|docs|spike|test|integration)/#i', $short ) ) {
			return true;
		}
		return substr_count( $short, '/' ) >= 2;
	}

	public static function branch( string $repo, string $ref ): array {
		$files = array_filter( explode( "\n", git_try( $repo, [ 'ls-tree', '--name-only', $ref ] ) ?? '' ) );
		$main  = null;
		$hdr   = [];
		foreach ( $files as $f ) {
			if ( substr( $f, -4 ) !== '.php' ) {
				continue;
			}
			$src = (string) git_show( $repo, $ref, $f );
			if ( preg_match( '/^[ \t\/*#@]*Plugin Name:\s*(.+)$/mi', substr( $src, 0, 8192 ), $m ) ) {
				$main = $f;
				foreach ( [ 'Plugin Name', 'Version', 'Requires at least', 'Requires PHP', 'Tested up to', 'WC requires at least', 'WC tested up to', 'Woo', 'Requires Plugins', 'Text Domain', 'Update URI' ] as $k ) {
					if ( preg_match( '/^[ \t\/*#@]*' . preg_quote( $k, '/' ) . ':\s*(.+)$/mi', substr( $src, 0, 8192 ), $mm ) ) {
						$hdr[ $k ] = trim( $mm[1] );
					}
				}
				if ( preg_match( "/define\(\s*['\"]([A-Z0-9_]*VERSION)['\"]\s*,\s*['\"]([^'\"]+)['\"]/", $src, $dm ) ) {
					$hdr['_version_const'] = $dm[1];
					$hdr['_version_const_value'] = $dm[2];
				}
				break;
			}
		}
		$readme = git_show( $repo, $ref, 'readme.txt' );
		$stable = $readme !== null && preg_match( '/^Stable tag:\s*(\S+)/mi', $readme, $sm ) ? $sm[1] : null;
		$adapter = null;
		foreach ( $files as $f ) {
			if ( preg_match( '/Adapter\.php$/', $f ) ) {
				$src     = (string) git_show( $repo, $ref, $f );
				$adapter = [ 'file' => $f, 'type' => preg_match( '/implements[^{]*LicenseConfigAdapter/', $src ) ? 'pro (LicenseConfigAdapter → EDD licensing)' : ( preg_match( '/implements[^{]*PluginMenuAdapter/', $src ) ? 'lite (PluginMenuAdapter)' : 'unknown' ) ];
				break;
			}
		}
		$composer = git_show( $repo, $ref, 'composer.json' );
		$shell    = $composer !== null && preg_match( '#"yaycommerce/admin-shell"\s*:\s*"([^"]+)"#', $composer, $cm ) ? $cm[1] : null;
		$scripts  = [];
		foreach ( [ 'release.sh', 'run.sh', 'scripts/run.sh', 'scripts/release.sh', 'scripts/build-free.js', 'scripts/validate-build.php', 'scripts/check-pro-boundary.sh', 'build-tools/strip-pro.js', '.distignore' ] as $s ) {
			if ( git_try( $repo, [ 'cat-file', '-e', "$ref:$s" ] ) !== null ) {
				$scripts[] = $s;
			}
		}
		$slugs = [];
		foreach ( $scripts as $s ) {
			$src = (string) git_show( $repo, $ref, $s );
			if ( preg_match_all( '/\b(?:SLUG|PLUGIN_SLUG|FOLDER_NAME|PLUGIN_NAME|plan_slug|slug)\s*=\s*["\']?([a-z0-9][a-z0-9-]+)["\']?/i', $src, $mm ) ) {
				$slugs = array_merge( $slugs, $mm[1] );
			}
			if ( preg_match_all( '/\b(pro|lite|free|premium)\)\s/', $src, $em ) ) {
				$slugs[] = 'flags:' . implode( '|', array_unique( $em[1] ) );
			}
			if ( preg_match( '/--lite\b|IS_PRO=|--plan=|package-free/', $src, $fm ) ) {
				$slugs[] = 'flag:' . $fm[0];
			}
		}
		$last = git_try( $repo, [ 'log', '-1', '--format=%cs %s', $ref ] );
		return [
			'ref' => $ref, 'last' => $last, 'main_file' => $main, 'headers' => $hdr, 'readme_stable_tag' => $stable,
			'has_readme' => $readme !== null, 'adapter' => $adapter, 'admin_shell' => $shell,
			'build_files' => $scripts, 'slug_hints' => array_values( array_unique( $slugs ) ),
		];
	}

	public static function repo( string $repo ): array {
		$refs = array_filter( explode( "\n", git( $repo, [ 'for-each-ref', '--format=%(refname:short)', 'refs/heads', 'refs/remotes' ] ) ) );
		$names = [];
		foreach ( $refs as $r ) {
			if ( $r === 'origin' || substr( $r, -5 ) === '/HEAD' ) {
				continue;
			}
			$n             = preg_replace( '#^origin/#', '', $r );
			$names[ $n ][] = $r;
		}
		$cands = [];
		$other = [];
		foreach ( $names as $n => $variants ) {
			$ref = in_array( $n, $variants, true ) ? $n : $variants[0];
			if ( self::looks_like_feature( $n ) ) {
				$other[] = $n;
				continue;
			}
			$info          = self::branch( $repo, $ref );
			$info['local'] = in_array( $n, $variants, true );
			$info['remote'] = in_array( "origin/$n", $variants, true );
			if ( $info['local'] && $info['remote'] ) {
				$ab                  = git_try( $repo, [ 'rev-list', '--left-right', '--count', "$n...origin/$n" ] );
				$info['ahead_behind_origin'] = $ab;
			}
			$cands[ $n ] = $info;
		}
		$tags = array_filter( explode( "\n", git_try( $repo, [ 'tag', '-l' ] ) ?? '' ) );
		$ign  = [];
		foreach ( [ '.claude/release.config.json', '.claude/release-kit/VERSION', 'dist/x', 'support/x.md' ] as $p ) {
			$ign[ $p ] = git_try( $repo, [ 'check-ignore', '-v', $p ] );
		}
		$svn = [];
		foreach ( glob( expand_home( '~/Documents/svns/*/trunk/readme.txt' ) ) ?: [] as $f ) {
			$t = (string) file_get_contents( $f );
			$svn[ basename( dirname( $f, 2 ) ) ] = [
				'stable_tag' => preg_match( '/^Stable tag:\s*(\S+)/mi', $t, $m ) ? $m[1] : null,
				'name'       => preg_match( '/^===\s*(.+?)\s*===/m', $t, $m ) ? $m[1] : null,
				'tags'       => array_map( 'basename', glob( dirname( $f, 2 ) . '/tags/*', GLOB_ONLYDIR ) ?: [] ),
			];
		}
		return [
			'repo' => $repo,
			'remote' => git_try( $repo, [ 'remote', 'get-url', 'origin' ] ),
			'current_branch' => git_try( $repo, [ 'branch', '--show-current' ] ),
			'dirty' => ( git_try( $repo, [ 'status', '--porcelain', '--untracked-files=no' ] ) ?? '' ) !== '',
			'candidates' => $cands,
			'other_branches' => $other,
			'tags' => array_values( $tags ),
			'ignored' => $ign,
			'tracked' => [
				'composer.lock'   => git_try( $repo, [ 'ls-files', '--error-unmatch', 'composer.lock' ] ) !== null,
				'vendor-prefixed' => ( git_try( $repo, [ 'ls-files', 'vendor-prefixed' ] ) ?? '' ) !== '',
			],
			'svn_checkouts' => $svn,
		];
	}
}

final class Baseline {
	/**
	 * Commits that may be the released $version of an edition, best first.
	 * A commit qualifies when its main file says $version. Scores: on the released branch (+3), the
	 * last commit before the version changed (+1), and, when an SVN tag folder is given, the share of
	 * its PHP/readme files whose content matches the commit byte for byte (×20). With SVN, every commit
	 * of the version run (up to 80) is scored; one `git ls-tree` per commit keeps that fast.
	 */
	public static function suggest( Config $cfg, array $e, string $version, ?string $svn_dir, int $limit ): array {
		$repo     = $cfg->repo;
		$resolved = [];
		foreach ( array_values( array_filter( [ $e['released_branch'] ?? null, $e['dev_branch'] ] ) ) as $b ) {
			foreach ( [ $b, "origin/$b" ] as $cand ) {
				if ( ref_exists( $repo, $cand ) ) {
					$resolved[ $b ] = $cand;
					break;
				}
			}
		}
		$svn_files = self::svn_hashes( $repo, $svn_dir );
		$cands     = [];
		foreach ( $resolved as $name => $ref ) {
			$log = git( $repo, [ 'log', '--format=%H', '-n', (string) $limit, $ref, '--', $e['main_file'], 'readme.txt' ] );
			foreach ( array_filter( explode( "\n", $log ) ) as $sha ) {
				if ( Version::check( Version::read( $cfg, $e, $sha, null ) )['version'] !== $version ) {
					continue;
				}
				// $sha is the newest commit touching the version files that says $version; later commits
				// (until the version changes) say it too. Walk forward to the tip of that run.
				$tip   = $sha;
				$run   = [ $sha ];
				foreach ( array_filter( explode( "\n", git( $repo, [ 'rev-list', '--reverse', '--first-parent', "$sha..$ref" ] ) ) ) as $later ) {
					if ( Version::check( Version::read( $cfg, $e, $later, null ) )['version'] !== $version ) {
						break;
					}
					$tip   = $later;
					$run[] = $later;
				}
				foreach ( ( $svn_files ? array_slice( $run, -80 ) : array_unique( [ $sha, $tip ] ) ) as $c ) {
					if ( isset( $cands[ $c ] ) ) {
						$cands[ $c ]['branches'][] = $name;
						continue;
					}
					$score   = ( $name === ( $e['released_branch'] ?? '' ) ? 3 : 0 ) + ( $c === $tip && ! $svn_files ? 1 : 0 );
					$match   = null;
					$differs = null;
					if ( $svn_files ) {
						$tree    = self::tree( $repo, $c );
						$differs = [];
						foreach ( $svn_files as $rel => $hash ) {
							if ( ( $tree[ $rel ] ?? null ) !== $hash ) {
								$differs[] = $rel;
							}
						}
						$match  = round( 1 - count( $differs ) / count( $svn_files ), 3 );
						$score += $match * 20;
					}
					$cands[ $c ] = [
						'sha' => $c, 'short' => substr( $c, 0, 8 ), 'date' => git( $repo, [ 'log', '-1', '--format=%cs', $c ] ),
						'subject' => git( $repo, [ 'log', '-1', '--format=%s', $c ] ), 'branches' => [ $name ],
						'kind' => $c === $tip ? 'last commit before the version changed' : ( $c === $sha ? 'first commit with this version' : "commit while the version was $version" ),
						'svn_match' => $match, 'svn_differs_count' => $differs === null ? null : count( $differs ),
						'svn_differs' => $differs === null ? null : array_slice( $differs, 0, 12 ), 'score' => round( $score, 2 ),
					];
				}
				break; // only the newest run per branch
			}
		}
		usort( $cands, static fn( $a, $b ) => $b['score'] <=> $a['score'] );
		return [ 'version' => $version, 'svn_dir' => $svn_dir, 'svn_files_compared' => count( $svn_files ), 'candidates' => array_values( $cands ) ];
	}

	/** path → blob hash of the PHP/readme files of an SVN folder (one git call). */
	private static function svn_hashes( string $repo, ?string $dir ): array {
		if ( ! $dir || ! is_dir( $dir ) ) {
			return [];
		}
		$dir  = rtrim( $dir, '/' );
		$rels = [];
		$it   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			$rel = substr( $f->getPathname(), strlen( $dir ) + 1 );
			if ( ! preg_match( '#(^|/)(vendor|vendor-prefixed|node_modules|assets/dist|build|\.svn)/#', $rel ) && preg_match( '/\.(php|txt)$/', $rel ) ) {
				$rels[] = $rel;
			}
		}
		if ( ! $rels ) {
			return [];
		}
		$list = (string) tempnam( sys_get_temp_dir(), 'yrsvn' );
		file_put_contents( $list, implode( "\n", array_map( static fn( $r ) => "$dir/$r", $rels ) ) . "\n" );
		[ $code, $out ] = run( [ 'bash', '-c', 'git -C "$1" hash-object --stdin-paths < "$2"', 'h', $repo, $list ] );
		unlink( $list );
		$hashes = explode( "\n", trim( $out ) );
		return $code === 0 && count( $hashes ) === count( $rels ) ? array_combine( $rels, $hashes ) : [];
	}

	/** path → blob hash of every file in a commit. */
	private static function tree( string $repo, string $sha ): array {
		$map = [];
		foreach ( explode( "\n", git( $repo, [ 'ls-tree', '-r', '--full-tree', $sha ] ) ) as $l ) {
			if ( preg_match( '/^\d+ blob ([0-9a-f]+)\t(.+)$/', $l, $m ) ) {
				$map[ $m[2] ] = $m[1];
			}
		}
		return $map;
	}
}

function cmd_detect( array $pos ): int {
	if ( ( $pos[1] ?? '' ) === 'baseline' ) {
		$cfg = Config::load();
		[ $plugin, $eds ] = $cfg->resolve( opt( 'plugin' ), opt( 'edition' ) );
		$svn = opt( 'svn-tag-dir' ) ? expand_home( (string) opt( 'svn-tag-dir' ) ) : null;
		out_json( Baseline::suggest( $cfg, $cfg->edition( $plugin, $eds[0] ), req( 'version' ), $svn, (int) opt( 'limit', '400' ) ) );
		return 0;
	}
	$repo = Config::repo_from_options();
	if ( ! $repo ) {
		throw new Fail( 'not in a git repo', EXIT_USAGE );
	}
	if ( opt( 'ref' ) ) {
		out_json( Detect::branch( $repo, (string) opt( 'ref' ) ) );
		return 0;
	}
	out_json( Detect::repo( $repo ) );
	return 0;
}
