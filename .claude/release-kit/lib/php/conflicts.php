<?php
/**
 * Settle the merge conflicts every release merge-back produces, and only those:
 *   yr conflicts resolve --tree=<worktree mid-merge> [--plugin=<p>]
 *
 * Merging a release branch (above all a hotfix cut from the live tag) into a dev branch that moved
 * on conflicts in the version locations and at the top of the changelog. Each conflict hunk is
 * settled only when it is one of these, in a version file or changelog file of the config:
 *   - version-only: both sides are the same text except for version numbers → each number takes
 *     the higher of the two (dev's 3.7.0 beats the hotfix's 3.6.3; Tested up to keeps the newer);
 *   - changelog entries: both sides are whole entries (start with an entry heading) → keep both,
 *     newer version first.
 *   - any other hunk, only when dev provably has every fix of the merged branch already: each
 *     non-"chore(release):" commit between the merge base and the merged branch is in dev
 *     (ancestor, patch-equivalent, or named by a "cherry picked from commit" trailer) → dev's side.
 *     A fix ported by hand counts only with that trailer in its message.
 * Anything else stays unresolved: exit 4 and nothing is staged, so the caller aborts the merge.
 * git.sh `merge --resolve-release-files` calls this after a conflicting merge.
 */

declare(strict_types=1);

namespace YR;

final class Conflicts {
	private const VER = '/\d+\.\d+(?:\.\d+){0,2}(?:-hotfix\.\d+)?/';

	/** @return array{0:?string,1:int,2:string[]} [resolved content or null, hunks, reasons] */
	public static function resolve_file( string $content, bool $release_file, bool $dev_has_fixes ): array {
		$lines = explode( "\n", $content );
		$out   = [];
		$hunks = 0;
		$why   = [];
		$state = null;
		$ours  = [];
		$theirs = [];
		foreach ( $lines as $l ) {
			if ( $state === null && strncmp( $l, '<<<<<<< ', 8 ) === 0 || $state === null && $l === '<<<<<<<' ) {
				$state = 'ours';
				$ours  = [];
				$theirs = [];
				continue;
			}
			if ( $state === 'ours' && strncmp( $l, '|||||||', 7 ) === 0 ) {
				$state = 'base';
				continue;
			}
			if ( ( $state === 'ours' || $state === 'base' ) && $l === '=======' ) {
				$state = 'theirs';
				continue;
			}
			if ( $state === 'theirs' && strncmp( $l, '>>>>>>>', 7 ) === 0 ) {
				$hunks++;
				$r = $release_file ? self::hunk( $ours, $theirs ) : null;
				if ( $r === null && $dev_has_fixes ) {
					$r = $ours; // dev already carries this branch's fixes; its side is the newer code
				}
				if ( $r === null ) {
					$why[] = 'hunk ' . $hunks . ': not a version-only or whole-changelog-entry conflict';
				} else {
					array_push( $out, ...$r );
				}
				$state = null;
				continue;
			}
			if ( $state === 'ours' ) {
				$ours[] = $l;
			} elseif ( $state === 'theirs' ) {
				$theirs[] = $l;
			} elseif ( $state === null ) {
				$out[] = $l;
			}
		}
		if ( $state !== null ) {
			$why[] = 'unterminated conflict markers';
		}
		return [ $why ? null : implode( "\n", $out ), $hunks, $why ];
	}

	/** @return string[]|null */
	private static function hunk( array $ours, array $theirs ): ?array {
		$a = implode( "\n", $ours );
		$b = implode( "\n", $theirs );
		// Version-only: identical once every version number is masked.
		if ( preg_replace( self::VER, '§', $a ) === preg_replace( self::VER, '§', $b ) ) {
			preg_match_all( self::VER, $b, $bm );
			$i = 0;
			$res = preg_replace_callback( self::VER, static function ( $m ) use ( $bm, &$i ) {
				$other = $bm[0][ $i++ ] ?? $m[0];
				return version_compare( $other, $m[0], '>' ) ? $other : $m[0];
			}, $a );
			return explode( "\n", $res );
		}
		// Whole changelog entries on both sides: keep both, newer first.
		$va = self::entry_version( $ours );
		$vb = self::entry_version( $theirs );
		if ( $va === null || $vb === null || version_compare( $va, $vb, '==' ) ) {
			return null;
		}
		$ta = self::trim_blank( $ours );
		$tb = self::trim_blank( $theirs );
		return version_compare( $va, $vb, '>' ) ? array_merge( $ta, [ '' ], $tb ) : array_merge( $tb, [ '' ], $ta );
	}

	/** Highest version in the entry headings of a hunk side whose first non-blank line is a heading. */
	private static function entry_version( array $side ): ?string {
		$side = self::trim_blank( $side );
		$head = static fn( $l ) => (bool) preg_match( '/^(=\s+[^=].*=\s*|##\s+\S.*)$/', $l );
		if ( ! $side || ! $head( $side[0] ) ) {
			return null;
		}
		$best = null;
		foreach ( $side as $l ) {
			if ( $head( $l ) && preg_match_all( self::VER, $l, $m ) ) {
				foreach ( $m[0] as $v ) {
					// A heading may hold a date (Nov 4, 2026); versions have at least one dot.
					if ( $best === null || version_compare( $v, $best, '>' ) ) {
						$best = $v;
					}
				}
			}
		}
		return $best;
	}

	private static function trim_blank( array $side ): array {
		while ( $side && trim( (string) $side[0] ) === '' ) {
			array_shift( $side );
		}
		while ( $side && trim( (string) end( $side ) ) === '' ) {
			array_pop( $side );
		}
		return $side;
	}
}

function cmd_conflicts( array $pos ): int {
	if ( ( $pos[1] ?? '' ) !== 'resolve' ) {
		throw new Fail( 'usage: yr conflicts resolve --tree=<worktree> [--plugin=<p>]', EXIT_USAGE );
	}
	$cfg  = Config::load();
	$tree = expand_home( req( 'tree' ) );
	$plugins = opt( 'plugin' ) ? [ (string) opt( 'plugin' ) ] : $cfg->plugins();
	$allowed = [];
	$changelogs = [];
	foreach ( $plugins as $p ) {
		foreach ( array_keys( $cfg->plugin( $p )['editions'] ) as $k ) {
			$e = $cfg->edition( $p, $k );
			foreach ( $e['version_files'] as $vf ) {
				$allowed[ $vf['path'] ] = true;
			}
			$cl = Changelog::format( $e )['file'];
			$allowed[ $cl ]    = true;
			$changelogs[ $cl ] = true;
		}
	}
	$files = array_filter( explode( "\n", git( $tree, [ 'diff', '--name-only', '--diff-filter=U' ] ) ) );
	// Does dev (HEAD) already have every fix commit of the branch being merged (MERGE_HEAD)?
	$missing = [];
	$mb      = git_try( $tree, [ 'merge-base', 'HEAD', 'MERGE_HEAD' ] );
	if ( $mb ) {
		$log = (string) git_try( $tree, [ 'log', '--no-merges', '--format=%H%x1f%s', trim( $mb ) . '..MERGE_HEAD' ] );
		foreach ( array_filter( explode( "\n", $log ) ) as $l ) {
			[ $sha, $subj ] = explode( "\x1f", $l, 2 ) + [ '', '' ];
			if ( strncmp( $subj, 'chore(release):', 15 ) !== 0 && ! SupportLog::has_commit( $tree, $sha, 'HEAD' ) ) {
				$missing[] = substr( $sha, 0, 8 ) . ' ' . $subj;
			}
		}
	}
	$dev_has_fixes = $mb && ! $missing;
	$resolved   = [];
	$unresolved = [];
	$writes     = [];
	foreach ( $files as $f ) {
		if ( empty( $allowed[ $f ] ) && ! $dev_has_fixes ) {
			$unresolved[] = [ 'path' => $f, 'why' => [ 'not a version or changelog file, and dev lacks: ' . implode( '; ', $missing ) ] ];
			continue;
		}
		[ $content, $hunks, $why ] = Conflicts::resolve_file( (string) file_get_contents( "$tree/$f" ), ! empty( $allowed[ $f ] ), $dev_has_fixes );
		if ( $why && $missing ) {
			$why[] = 'dev does not have: ' . implode( '; ', $missing ) . ' (port with cherry-pick -x, or add "(cherry picked from commit <sha>)" to the hand-ported commit)';
		}
		if ( $content === null ) {
			$unresolved[] = [ 'path' => $f, 'why' => $why ];
			continue;
		}
		if ( ! empty( $changelogs[ $f ] ) ) {
			$content = preg_replace( "/\n{3,}/", "\n\n", $content );
		}
		$writes[ $f ] = $content;
		$resolved[]   = [ 'path' => $f, 'hunks' => $hunks ];
	}
	if ( ! $unresolved && ! Ctx::$dry ) {
		foreach ( $writes as $f => $content ) {
			file_put_contents( "$tree/$f", $content );
		}
		// The result must still read as one version per edition.
		foreach ( $plugins as $p ) {
			foreach ( array_keys( $cfg->plugin( $p )['editions'] ) as $k ) {
				$chk = Version::check( Version::read( $cfg, $cfg->edition( $p, $k ), null, $tree ) );
				if ( ! $chk['consistent'] ) {
					$unresolved[] = [ 'path' => "$p/$k version locations", 'why' => $chk['problems'] ];
				}
			}
		}
		if ( ! $unresolved ) {
			foreach ( array_keys( $writes ) as $f ) {
				git( $tree, [ 'add', '--', $f ] );
			}
		}
	}
	out_json( [ 'resolved' => $resolved, 'unresolved' => $unresolved, 'dev_has_every_fix' => $dev_has_fixes, 'dry_run' => Ctx::$dry ] );
	return $unresolved ? EXIT_CONFLICT : 0;
}
