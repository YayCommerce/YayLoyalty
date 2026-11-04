<?php
/**
 * Reads commits in a range and classifies each one for version proposals and changelog drafts.
 *
 * Our repos mix Conventional Commits (`fix(gift): …`), changelog-style prefixes (`Fixed: …`,
 * `Added: …`), squash-merged branch names (`Onyx/feat/order details options (#251)`) and free text
 * (`#Fix …`, `Up ver`). Each commit gets a type, a confidence, and whether it is internal (left
 * out of customer changelogs). Low confidence means "a person should look".
 */

declare(strict_types=1);

namespace YR;

final class Commits {
	public const TYPES_INTERNAL = [ 'docs', 'chore', 'test', 'tests', 'build', 'ci', 'style', 'refactor', 'wip', 'revert-internal' ];

	/** Words that map onto our types, for conventional types and changelog-style prefixes. */
	private const WORDS = [
		'feat' => 'feat', 'feature' => 'feat', 'add' => 'feat', 'added' => 'feat', 'new' => 'feat', 'integration' => 'feat',
		'fix' => 'fix', 'fixed' => 'fix', 'fixes' => 'fix', 'bugfix' => 'fix', 'hotfix' => 'fix', 'bug' => 'fix',
		'perf' => 'perf', 'performance' => 'perf',
		'improve' => 'improve', 'improved' => 'improve', 'improvement' => 'improve', 'enhance' => 'improve',
		'enhancement' => 'improve', 'update' => 'improve', 'updated' => 'improve', 'tweak' => 'improve',
		'change' => 'improve', 'changed' => 'improve', 'remove' => 'improve', 'removed' => 'improve',
		'security' => 'security', 'sec' => 'security',
		'compat' => 'compat', 'compatibility' => 'compat',
		'docs' => 'docs', 'doc' => 'docs', 'chore' => 'chore', 'test' => 'test', 'tests' => 'test', 'build' => 'build',
		'ci' => 'ci', 'style' => 'style', 'refactor' => 'refactor', 'wip' => 'wip', 'revert' => 'revert',
	];

	/**
	 * @return array<int,array<string,mixed>> newest first
	 */
	public static function log( string $repo, string $range, ?string $path_filter = null ): array {
		$args = [ 'log', '--no-merges', '--format=%H%x1f%h%x1f%an%x1f%aI%x1f%s%x1f%b%x1e', $range ];
		if ( $path_filter ) {
			$args[] = '--';
			$args[] = $path_filter;
		}
		$raw = git( $repo, $args );
		$out = [];
		foreach ( explode( "\x1e", $raw ) as $rec ) {
			$rec = ltrim( $rec, "\n" );
			if ( $rec === '' ) {
				continue;
			}
			$f = explode( "\x1f", $rec );
			if ( count( $f ) < 6 ) {
				continue;
			}
			$c = [
				'sha'     => $f[0],
				'short'   => $f[1],
				'author'  => $f[2],
				'date'    => substr( $f[3], 0, 10 ),
				'subject' => $f[4],
				'body'    => trim( $f[5] ),
			];
			$out[] = $c + self::classify( $c['subject'], $c['body'] );
		}
		return $out;
	}

	/** @return array{type:string,scope:?string,text:string,pr:?int,breaking:bool,internal:bool,confidence:string,why:string,picked_from:?string} */
	public static function classify( string $subject, string $body = '' ): array {
		$s     = trim( $subject );
		$pr    = preg_match( '/\(#(\d+)\)\s*$/', $s, $m ) ? (int) $m[1] : null;
		$clean = trim( preg_replace( '/\s*\(#\d+\)\s*$/', '', $s ) );
		$clean = ltrim( $clean, '#' );
		$clean = trim( $clean );
		$picked = preg_match( '/\(cherry picked from commit ([0-9a-f]{7,40})\)/', $body, $pm ) ? $pm[1] : null;

		$r = [
			'type' => 'unknown', 'scope' => null, 'text' => $clean, 'pr' => $pr, 'breaking' => false,
			'internal' => false, 'confidence' => 'low', 'why' => 'no recognised prefix', 'picked_from' => $picked,
		];

		// 1. Internal housekeeping, whatever the prefix.
		if ( preg_match( '/^(up ?ver(sion)?\b|bump(ed)?\b|bump version|update(d)? (the )?version|version bump|release v?\d|merge\b|wip\b|clean ?code|fix(ed)? (phpcs|lint|style|typo|cs)\b|phpcs\b|lint\b|format(ting)?\b|update (readme|changelog)\b)/i', $clean ) ) {
			return [ 'type' => 'chore', 'internal' => true, 'confidence' => 'high', 'why' => 'housekeeping subject' ] + $r;
		}
		// Edits to docs files ("Update notes.md", "readme.md tweaks").
		if ( preg_match( '/(^|\s)[\w.\/-]+\.(md|markdown)\b/i', $clean ) && ! preg_match( '/^(feat|fix|perf)\b/i', $clean ) ) {
			return [ 'type' => 'docs', 'internal' => true, 'confidence' => 'high', 'why' => 'docs file' ] + $r;
		}
		// "tested up to", "WC tested" → compatibility note, worth a changelog line.
		if ( preg_match( '/\b(wc |woocommerce |wp |wordpress )?tested up to\b|\bcompatib(le|ility) with (wordpress|woocommerce|wp|wc)\b/i', $clean ) ) {
			return [ 'type' => 'compat', 'confidence' => 'high', 'why' => 'tested-up-to bump' ] + $r;
		}

		if ( preg_match( '/^revert\b/i', $clean ) ) {
			return [ 'type' => 'revert', 'confidence' => 'low', 'why' => 'revert: check whether the reverted change was ever released' ] + $r;
		}
		// 2. Conventional commit or changelog-style prefix: `type(scope)!: text` / `Fixed: text`.
		if ( preg_match( '/^(?<type>[A-Za-z]+)(?:\((?<scope>[^)]*)\))?(?<bang>!)?\s*:\s*(?<text>.+)$/', $clean, $m ) ) {
			$word = strtolower( $m['type'] );
			if ( isset( self::WORDS[ $word ] ) ) {
				$type = self::WORDS[ $word ];
				$r    = [
					'type' => $type, 'scope' => $m['scope'] !== '' ? $m['scope'] : null, 'text' => trim( $m['text'] ),
					'breaking' => $m['bang'] === '!', 'confidence' => 'high', 'why' => "prefix '{$m['type']}'",
				] + $r;
			}
		} elseif ( preg_match( '#^(?:[A-Za-z0-9_.-]+/)?(?<type>feat|feature|fix|hotfix|bugfix|improve|improvement|perf|integration|refactor|chore|docs|test)/(?<text>.+)$#i', $clean, $m ) ) {
			// 3. Squash-merged branch name: "Onyx/feat/order details options".
			$type = self::WORDS[ strtolower( $m['type'] ) ] ?? 'unknown';
			$r    = [ 'type' => $type, 'text' => str_replace( [ '-', '_' ], ' ', trim( $m['text'] ) ), 'confidence' => 'high', 'why' => 'squash-merged branch name' ] + $r;
		} elseif ( preg_match( '/^(?<verb>fix(e[sd])?|resolve[sd]?|correct(ed)?|prevent(ed)?|add(ed|s)?|new|introduce[sd]?|support(s|ed)?|improve[sd]?|update[sd]?|enhance[sd]?|optimi[sz]e[sd]?|tweak(ed)?|refine[sd]?|remove[sd]?|rework(ed)?)\b/i', $clean, $m ) ) {
			// 4. Free text starting with a verb: lower confidence.
			$v    = strtolower( $m['verb'] );
			$type = preg_match( '/^(fix|resolve|correct|prevent)/', $v ) ? 'fix' : ( preg_match( '/^(add|new|introduce|support)/', $v ) ? 'feat' : 'improve' );
			$r    = [ 'type' => $type, 'confidence' => 'low', 'why' => "starts with '{$m['verb']}'" ] + $r;
		}

		if ( in_array( $r['type'], self::TYPES_INTERNAL, true ) ) {
			$r['internal'] = true;
		}
		if ( $r['type'] === 'revert' ) {
			$r['confidence'] = 'low';
			$r['why']        = 'revert: check whether the reverted change was ever released';
		}
		if ( preg_match( '/BREAKING[ -]CHANGE/', $body ) ) {
			$r['breaking'] = true;
		}
		// 5. Security wording upgrades a user-facing fix.
		if ( ! $r['internal'] && preg_match( '/\b(xss|csrf|nonce|saniti[sz]|escap(e|ing)|sql injection|object injection|vulnerab|capabilit(y|ies) check|permission check|unauthori[sz]ed|privilege)/i', $clean . ' ' . $body ) ) {
			$r['type'] = 'security';
			$r['why'] .= '; security wording';
		}
		return $r;
	}

	/**
	 * Proposed bump for a set of classified commits.
	 *
	 * @return array{bump:string,reasons:string[],uncertain:string[]}
	 */
	public static function propose_bump( array $commits ): array {
		$bump      = 'none';
		$reasons   = [];
		$uncertain = [];
		$rank      = [ 'none' => 0, 'patch' => 1, 'minor' => 2, 'major' => 3 ];
		$raise     = static function ( string $to, string $why ) use ( &$bump, &$reasons, $rank ) {
			if ( $rank[ $to ] > $rank[ $bump ] ) {
				$bump = $to;
			}
			$reasons[] = $why;
		};
		$low_feats = 0;
		foreach ( $commits as $c ) {
			if ( $c['internal'] ) {
				continue;
			}
			$label = "{$c['short']} {$c['subject']}";
			if ( $c['breaking'] ) {
				$raise( 'major', "breaking: $label" );
			} elseif ( $c['type'] === 'feat' && $c['confidence'] === 'high' ) {
				$raise( 'minor', "feature: $label" );
			} elseif ( $c['type'] === 'feat' ) {
				$low_feats++;
				$raise( 'patch', "maybe a feature: $label" );
				$uncertain[] = "looks like a feature, counted as patch: $label";
			} elseif ( in_array( $c['type'], [ 'fix', 'perf', 'security', 'improve', 'compat' ], true ) ) {
				$raise( 'patch', "{$c['type']}: $label" );
				if ( $c['confidence'] === 'low' ) {
					$uncertain[] = "guessed '{$c['type']}' ({$c['why']}): $label";
				}
			} else {
				$raise( 'patch', "unclassified: $label" );
				$uncertain[] = "could not classify, counted as patch: $label";
			}
		}
		if ( $low_feats > 0 && $bump === 'patch' ) {
			$uncertain[] = "$low_feats commit(s) may be features; consider a minor bump";
		}
		return [ 'bump' => $bump, 'reasons' => $reasons, 'uncertain' => $uncertain ];
	}
}

function cmd_commits( array $pos ): int {
	$cfg = Config::load();
	$range = req( 'range' );
	$list  = Commits::log( $cfg->repo, $range );
	out_json( [ 'range' => $range, 'commits' => $list, 'proposal' => Commits::propose_bump( $list ) ] );
	return 0;
}
