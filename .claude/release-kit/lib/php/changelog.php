<?php
/**
 * Changelog data and files.
 *
 *   collect  raw material for a draft: classified commits since the edition's release, the
 *            unreleased support fixes, and any "Unreleased"/"TBD" block already in the file.
 *   render   a draft JSON → text in the edition's format (readme.txt or CHANGELOG.md style).
 *   insert   put a rendered entry into the edition's changelog file (replacing the same version's
 *            entry on a resubmission, and an Unreleased/TBD block when asked to).
 *
 * The wording is the skill's job (.claude/release-kit/reference/changelog-style.md); this file only moves data and text.
 * Draft shape: {"version":"1.2.3","date":"2026-11-04","entries":[{"group":"fixed","text":"…"}]}
 */

declare(strict_types=1);

namespace YR;

final class Changelog {
	public const GROUPS = [ 'new', 'improved', 'fixed', 'security' ];

	public static function format( array $e ): array {
		$c       = $e['changelog'] ?? [];
		$is_md   = substr( (string) ( $c['file'] ?? 'CHANGELOG.md' ), -3 ) === '.md';
		$default = $is_md
			? [ 'file' => 'CHANGELOG.md', 'section' => '# Changelog', 'heading' => '## {version} - {date:Y-m-d}', 'line' => '- {prefix}: {text}' ]
			: [ 'file' => 'readme.txt', 'section' => '== Changelog ==', 'heading' => '= {date:M j, Y} - Version {version} =', 'line' => '- {prefix}: {text}' ];
		$c += $default;
		$c['prefixes'] = ( $c['prefixes'] ?? [] ) + [ 'new' => 'Added', 'improved' => 'Improved', 'fixed' => 'Fixed', 'security' => 'Security' ];
		$c['order']    = $c['order'] ?? [ 'new', 'improved', 'fixed', 'security' ];
		return $c;
	}

	public static function render( array $e, array $draft ): string {
		$f    = self::format( $e );
		$date = strtotime( ( $draft['date'] ?? gmdate( 'Y-m-d' ) ) . ' 12:00:00 UTC' );
		$head = preg_replace_callback( '/\{date:([^}]+)\}/', static fn( $m ) => gmdate( $m[1], $date ), $f['heading'] );
		$head = str_replace( '{version}', (string) $draft['version'], $head );
		$lines = [ $head ];
		foreach ( $f['order'] as $g ) {
			foreach ( $draft['entries'] ?? [] as $en ) {
				if ( ( $en['group'] ?? '' ) !== $g ) {
					continue;
				}
				$text    = rtrim( trim( (string) $en['text'] ), '.' );
				$lines[] = strtr( $f['line'], [ '{prefix}' => $f['prefixes'][ $g ], '{text}' => $text ] );
			}
		}
		foreach ( $draft['entries'] ?? [] as $en ) {
			if ( ! in_array( $en['group'] ?? '', self::GROUPS, true ) ) {
				throw new Fail( "draft entry has unknown group '" . ( $en['group'] ?? '' ) . "' (use " . implode( '|', self::GROUPS ) . ')' );
			}
		}
		return implode( "\n", $lines ) . "\n";
	}

	/** Is a heading line the start of an entry? */
	private static function is_heading( string $line, bool $md ): bool {
		return $md ? (bool) preg_match( '/^##\s+\S/', $line ) : (bool) preg_match( '/^=\s+[^=].*=\s*$/', $line );
	}

	/**
	 * Insert $entry under the section heading. Replaces an existing entry for the same version, and
	 * an Unreleased/TBD entry when $replace_unreleased.
	 *
	 * @return array{content:string,replaced:string[],created_section:bool}
	 */
	public static function insert( string $content, array $e, string $entry, string $version, bool $replace_unreleased ): array {
		$f        = self::format( $e );
		$md       = substr( $f['file'], -3 ) === '.md';
		$lines    = $content === '' ? [] : explode( "\n", $content );
		$replaced = [];
		$sec      = null;
		foreach ( $lines as $i => $l ) {
			if ( trim( $l ) === trim( $f['section'] ) ) {
				$sec = $i;
				break;
			}
		}
		$created = false;
		if ( $sec === null ) {
			if ( $md ) {
				array_unshift( $lines, $f['section'], '' );
				$sec = 0;
			} else {
				if ( $lines && end( $lines ) !== '' ) {
					$lines[] = '';
				}
				$lines[] = $f['section'];
				$lines[] = '';
				$sec     = count( $lines ) - 2;
			}
			$created = true;
		}
		// Find the section's end (next "== x ==" for readme; end of file for md).
		$end = count( $lines );
		if ( ! $md ) {
			for ( $i = $sec + 1; $i < count( $lines ); $i++ ) {
				if ( preg_match( '/^==\s.*\s==\s*$/', $lines[ $i ] ) ) {
					$end = $i;
					break;
				}
			}
		}
		// Collect entry blocks inside the section.
		$blocks = [];
		$cur    = null;
		for ( $i = $sec + 1; $i < $end; $i++ ) {
			if ( self::is_heading( $lines[ $i ], $md ) ) {
				if ( $cur !== null ) {
					$blocks[] = $cur;
				}
				$cur = [ 'start' => $i, 'end' => $i + 1, 'head' => $lines[ $i ] ];
			} elseif ( $cur !== null ) {
				$cur['end'] = $i + 1;
			}
		}
		if ( $cur !== null ) {
			$blocks[] = $cur;
		}
		$drop = [];
		foreach ( $blocks as $b ) {
			$same    = preg_match( '/(^|[^0-9.])' . preg_quote( $version, '/' ) . '([^0-9.]|$)/', $b['head'] );
			$unrel   = preg_match( '/\b(unreleased|tbd|upcoming|next)\b/i', $b['head'] );
			if ( $same || ( $replace_unreleased && $unrel ) ) {
				$drop[]     = $b;
				$replaced[] = trim( $b['head'] );
			}
		}
		foreach ( array_reverse( $drop ) as $b ) {
			// Also drop the blank line that followed the block.
			$len = $b['end'] - $b['start'];
			array_splice( $lines, $b['start'], $len );
		}
		// Newest entry goes first, right under the section heading; blank-line runs collapse below.
		$ins = array_merge( [ '' ], explode( "\n", rtrim( $entry, "\n" ) ), [ '' ] );
		array_splice( $lines, $sec + 1, 0, $ins );
		$out = preg_replace( "/\n{3,}/", "\n\n", implode( "\n", $lines ) );
		return [ 'content' => rtrim( $out, "\n" ) . "\n", 'replaced' => $replaced, 'created_section' => $created ];
	}

	/** Existing Unreleased/TBD entry inside the changelog section, so a draft can fold it in. */
	public static function unreleased_block( string $content, array $e ): ?string {
		$f      = self::format( $e );
		$md     = substr( $f['file'], -3 ) === '.md';
		$out    = [];
		$in_sec = false;
		$in     = false;
		foreach ( explode( "\n", $content ) as $l ) {
			if ( trim( $l ) === trim( $f['section'] ) ) {
				$in_sec = true;
				continue;
			}
			if ( ! $in_sec ) {
				continue;
			}
			if ( ! $md && preg_match( '/^==\s.*\s==\s*$/', $l ) ) {
				break; // next readme section
			}
			if ( self::is_heading( $l, $md ) ) {
				if ( $in ) {
					break;
				}
				$in = (bool) preg_match( '/\b(unreleased|tbd|upcoming)\b/i', $l );
			}
			if ( $in ) {
				$out[] = $l;
			}
		}
		return $out ? trim( implode( "\n", $out ) ) : null;
	}
}

function cmd_changelog( array $pos ): int {
	$sub = $pos[1] ?? 'collect';
	$cfg = Config::load();
	[ $plugin, $eds ] = $cfg->resolve( opt( 'plugin' ), opt( 'edition' ) );
	$k = $eds[0];
	$e = $cfg->edition( $plugin, $k );

	if ( $sub === 'collect' ) {
		$head = opt( 'ref', $e['dev_branch'] );
		$base = opt( 'since' ) ? [ 'ref' => opt( 'since' ), 'version' => null, 'source' => '--since' ] : Version::base( $cfg, $plugin, $k );
		if ( ! $base ) {
			throw new Fail( "$plugin/$k has no release tag or confirmed baseline; run yayinit first", EXIT_NOT_CONFIG );
		}
		$commits = ! empty( $base['first_release'] ) ? [] : Commits::log( $cfg->repo, $base['ref'] . '..' . $head );
		$fmt     = Changelog::format( $e );
		$file    = git_show( $cfg->repo, $head, $fmt['file'] );
		$support = SupportLog::rows( SupportLog::read( $cfg, $e, $e['dev_branch'] ), 'unreleased' );
		$user    = array_values( array_filter( $commits, static fn( $c ) => ! $c['internal'] ) );
		$dropped = array_values( array_filter( $commits, static fn( $c ) => $c['internal'] ) );
		out_json( [
			'plugin' => $plugin, 'edition' => $k, 'target' => $e['target'],
			'range' => ( $base['ref'] ?? '(start)' ) . '..' . $head, 'base' => $base,
			'first_release' => ! empty( $base['first_release'] ),
			'changelog_file' => $fmt['file'], 'format' => $fmt,
			'existing_unreleased' => $file !== null ? Changelog::unreleased_block( $file, $e ) : null,
			'commits' => array_map( static fn( $c ) => array_intersect_key( $c, array_flip( [ 'short', 'date', 'subject', 'body', 'type', 'scope', 'text', 'pr', 'breaking', 'confidence', 'why' ] ) ), $user ),
			'internal_dropped' => array_map( static fn( $c ) => "{$c['short']} {$c['subject']}", $dropped ),
			'support_fixes' => $support,
			'style_guide' => kit_dir() . '/reference/changelog-style.md',
		] );
		return 0;
	}
	if ( $sub === 'render' ) {
		echo Changelog::render( $e, read_json( req( 'draft' ) ) );
		return 0;
	}
	if ( $sub === 'insert' ) {
		$draft = read_json( req( 'draft' ) );
		$tree  = expand_home( req( 'tree' ) );
		$fmt   = Changelog::format( $e );
		$file  = "$tree/{$fmt['file']}";
		$entry = Changelog::render( $e, $draft );
		$res   = Changelog::insert( is_file( $file ) ? (string) file_get_contents( $file ) : '', $e, $entry, (string) $draft['version'], flag( 'replace-unreleased' ) );
		write_file( $file, $res['content'], 'changelog ' . $draft['version'] );
		out_json( [ 'file' => $fmt['file'], 'entry' => $entry, 'replaced' => $res['replaced'], 'created_section' => $res['created_section'], 'dry_run' => Ctx::$dry ] );
		return 0;
	}
	throw new Fail( "unknown: changelog $sub (collect|render|insert)", EXIT_USAGE );
}
