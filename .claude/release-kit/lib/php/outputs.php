<?php
/**
 * Documents the kit hands to people: worker reports (REPORT.json + REPORT.md), the consolidated
 * review table for yayorchestrate, and the manager's HANDOFF.md files. Templates live in
 * templates/; nothing here uploads or sends anything.
 */

declare(strict_types=1);

namespace YR;

final class Report {
	public const STATUSES = [ 'ok', 'failed', 'blocked' ];
	public const REQUIRED = [ 'plugin', 'edition', 'action', 'status' ];

	public static function normalize( array $r ): array {
		foreach ( self::REQUIRED as $k ) {
			if ( empty( $r[ $k ] ) ) {
				throw new Fail( "report is missing '$k'" );
			}
		}
		if ( ! in_array( $r['status'], self::STATUSES, true ) ) {
			throw new Fail( "report status must be ok, failed or blocked (got {$r['status']})" );
		}
		return $r + [
			'version' => [ 'current' => null, 'proposed' => null, 'why' => [] ],
			'branch' => null, 'sha' => null, 'zips' => [], 'shared_code' => null, 'gates' => null,
			'changelog_draft' => null, 'cross_edition' => [], 'warnings' => [], 'questions' => [], 'blocked_reason' => null,
			'date' => now_iso(), 'kit_version' => kit_version(),
		];
	}

	public static function markdown( array $r ): string {
		$v  = $r['version'];
		$md = [ "# {$r['plugin']} / {$r['edition']}: {$r['action']} → **{$r['status']}**", '' ];
		if ( $r['blocked_reason'] ) {
			$md[] = "**Blocked:** {$r['blocked_reason']}";
			$md[] = '';
		}
		$md[] = '- Version: ' . ( $v['current'] ?? '?' ) . ( ! empty( $v['proposed'] ) ? " → {$v['proposed']}" : '' );
		foreach ( (array) ( $v['why'] ?? [] ) as $w ) {
			$md[] = "  - $w";
		}
		$md[] = '- Branch: ' . ( $r['branch'] ?? '—' ) . ' @ ' . ( $r['sha'] ? substr( $r['sha'], 0, 8 ) : '—' );
		$md[] = '- Shared code: ' . self::shared_line( $r['shared_code'] );
		foreach ( $r['zips'] as $z ) {
			$md[] = "- Zip: `{$z['file']}` sha256 `{$z['sha256']}`";
		}
		if ( $r['gates'] ) {
			$s    = $r['gates']['summary'] ?? [];
			$md[] = sprintf( '- Gates: %s (%d pass, %d fail, %d warn, %d skip)', ! empty( $r['gates']['ok'] ) ? 'passed' : 'FAILED', $s['pass'] ?? 0, $s['fail'] ?? 0, $s['warn'] ?? 0, $s['skip'] ?? 0 );
			foreach ( $r['gates']['results'] ?? [] as $g ) {
				if ( $g['status'] === 'fail' || $g['status'] === 'warn' ) {
					$md[] = "  - {$g['status']} {$g['id']}: {$g['summary']}";
				}
			}
		}
		foreach ( [ 'cross_edition' => 'Cross-edition', 'warnings' => 'Warnings', 'questions' => 'Open questions' ] as $k => $title ) {
			if ( $r[ $k ] ) {
				$md[] = '';
				$md[] = "## $title";
				foreach ( $r[ $k ] as $x ) {
					$md[] = '- ' . ( is_array( $x ) ? json_encode( $x, JSON_UNESCAPED_SLASHES ) : $x );
				}
			}
		}
		if ( $r['changelog_draft'] ) {
			$md[] = '';
			$md[] = '## Changelog draft';
			$md[] = '';
			$md[] = '```';
			$md[] = rtrim( is_array( $r['changelog_draft'] ) ? ( $r['changelog_draft']['rendered'] ?? json_encode( $r['changelog_draft'] ) ) : $r['changelog_draft'] );
			$md[] = '```';
		}
		return implode( "\n", $md ) . "\n";
	}

	public static function shared_line( $sc ): string {
		if ( ! $sc ) {
			return '—';
		}
		$parts = [];
		foreach ( $sc as $pkg => $p ) {
			$parts[] = "$pkg {$p['ref']} ({$p['short']}, reports {$p['const']})";
		}
		return implode( '; ', $parts );
	}

	/** One table + all changelog drafts + conflicts, for the consolidated review. */
	public static function consolidated( array $reports ): string {
		$o = [ '| # | Plugin | Edition | Action | Status | Version | Gates | Shared code | Warnings |', '|---|---|---|---|---|---|---|---|---|' ];
		$shared_seen = [];
		$i = 0;
		foreach ( $reports as $r ) {
			$i++;
			$v   = $r['version'];
			$ver = ( $v['current'] ?? '?' ) . ( ! empty( $v['proposed'] ) && $v['proposed'] !== $v['current'] ? " → {$v['proposed']}" : '' );
			$g   = $r['gates'] ? ( ! empty( $r['gates']['ok'] ) ? 'pass' : 'FAIL' ) . sprintf( ' (%dw)', $r['gates']['summary']['warn'] ?? 0 ) : '—';
			$o[] = "| $i | {$r['plugin']} | {$r['edition']} | {$r['action']} | **{$r['status']}** | $ver | $g | " . self::shared_line( $r['shared_code'] ) . ' | ' . count( $r['warnings'] ) . ' |';
			foreach ( (array) $r['shared_code'] as $pkg => $p ) {
				$shared_seen[ $pkg ][ $p['sha'] ?? '?' ][] = "{$r['plugin']}/{$r['edition']}";
			}
		}
		$md = implode( "\n", $o ) . "\n";
		$conf = [];
		foreach ( $shared_seen as $pkg => $by_sha ) {
			if ( count( $by_sha ) > 1 ) {
				$conf[] = "- **$pkg differs between rows** (the highest version wins at runtime; releasing them together needs your explicit approval): " . implode( '; ', array_map( static fn( $sha, $who ) => substr( (string) $sha, 0, 8 ) . ': ' . implode( ', ', $who ), array_keys( $by_sha ), $by_sha ) );
			}
		}
		foreach ( $reports as $r ) {
			foreach ( $r['cross_edition'] as $x ) {
				if ( is_array( $x ) && ( $x['kind'] ?? '' ) === 'sibling-version' ) {
					$conf[] = "- {$r['plugin']}/{$r['edition']}: sibling {$x['edition']} would be left at {$x['version']} (shared version policy)";
				}
			}
		}
		if ( $conf ) {
			$md .= "\n**Conflicts**\n\n" . implode( "\n", $conf ) . "\n";
		}
		foreach ( $reports as $r ) {
			if ( $r['status'] !== 'ok' ) {
				$md .= "\n**{$r['plugin']}/{$r['edition']} {$r['status']}**: " . ( $r['blocked_reason'] ?? implode( '; ', array_slice( $r['warnings'], 0, 3 ) ) ) . "\n";
			}
			if ( $r['questions'] ) {
				$md .= "\n**{$r['plugin']}/{$r['edition']} asks:**\n" . implode( "\n", array_map( static fn( $q ) => "- $q", $r['questions'] ) ) . "\n";
			}
		}
		foreach ( $reports as $r ) {
			if ( $r['changelog_draft'] ) {
				$md .= "\n### Changelog: {$r['plugin']} / {$r['edition']}\n\n```\n" . rtrim( is_array( $r['changelog_draft'] ) ? ( $r['changelog_draft']['rendered'] ?? '' ) : $r['changelog_draft'] ) . "\n```\n";
			}
		}
		return $md;
	}
}

final class Handoff {
	public static function edition( Config $cfg, array $e, array $build, ?string $changelog, ?string $notes, ?string $changes_since ): string {
		$tpl = (string) file_get_contents( kit_dir() . "/templates/handoff-{$e['target']}.md" );
		$vars = [
			'label' => $e['label'] ?? "{$e['plugin']} {$e['key']}", 'plugin' => $e['plugin'], 'edition' => $e['key'],
			'target' => $e['target'], 'slug' => $e['slug'], 'version' => $build['version'],
			'zip' => $build['zip']['file'], 'zip_path' => $build['zip']['path'], 'sha256' => $build['zip']['sha256'],
			'size_mb' => round( $build['zip']['size'] / 1048576, 2 ), 'git_sha' => $build['git']['sha'], 'branch' => $build['git']['branch'] ?? '',
			'shared_code' => Report::shared_line( $build['shared_code'] ?? null ), 'date' => gmdate( 'Y-m-d' ),
			'changelog' => trim( (string) $changelog ) ?: '(no changelog entry)',
			'notes' => trim( (string) $notes ) ?: 'Nothing unusual.',
			'changes_since' => $changes_since ? "## Changes since previous submission\n\n" . trim( $changes_since ) . "\n" : '',
			'store_item' => $e['store']['item'] ?? '(see config: store.item)', 'store_url' => $e['store']['url'] ?? '',
			'gates' => $build['gates'] ? sprintf( '%d pass, %d warn, %d skip', $build['gates']['summary']['pass'], $build['gates']['summary']['warn'], $build['gates']['summary']['skip'] ) : 'not run',
			'resubmission' => $build['resubmission'] ? "This is resubmission r{$build['resubmission']} of {$build['version']}." : '',
		];
		return fill( $tpl, $vars );
	}

	public static function combined( array $items, string $date ): string {
		$by = [ 'wporg' => [], 'edd' => [], 'woo' => [] ];
		foreach ( $items as $it ) {
			$by[ $it['target'] ][] = $it;
		}
		$titles = [ 'wporg' => 'WordPress.org (SVN)', 'edd' => 'EDD store (yaycommerce.com)', 'woo' => 'WooCommerce Marketplace' ];
		$md = [ "# Release handoff, $date", '', 'Upload each zip yourself; the kit uploaded nothing. Check each checksum before uploading (`shasum -a 256 <zip>`).', '' ];
		foreach ( $by as $t => $list ) {
			if ( ! $list ) {
				continue;
			}
			$md[] = "## {$titles[$t]}";
			$md[] = '';
			$md[] = '| Plugin | Edition | Version | Zip | sha256 | Details |';
			$md[] = '|---|---|---|---|---|---|';
			foreach ( $list as $it ) {
				$md[] = "| {$it['plugin']} | {$it['edition']} | {$it['version']} | `{$it['zip']}` | `" . substr( $it['sha256'], 0, 16 ) . "…` | [{$it['handoff_name']}]({$it['handoff_path']}) |";
			}
			$md[] = '';
		}
		$md[] = 'When a release is live everywhere, tell the developer which builds were accepted where, so they can run `/yayfinalize`.';
		return implode( "\n", $md ) . "\n";
	}
}

function cmd_report( array $pos ): int {
	$sub = $pos[1] ?? 'write';
	if ( $sub === 'write' ) {
		$cfg = Config::load();
		$r   = Report::normalize( read_json( req( 'data' ) ) );
		$dir = opt( 'dir' ) ? expand_home( (string) opt( 'dir' ) ) : $cfg->dist_dir( $r['plugin'], $r['edition'] );
		write_json( "$dir/REPORT.json", $r );
		write_file( "$dir/REPORT.md", Report::markdown( $r ) );
		echo Report::markdown( $r );
		return 0;
	}
	if ( $sub === 'collect' ) {
		$reports = [];
		foreach ( opt_list( 'files' ) as $f ) {
			$reports[] = Report::normalize( read_json( expand_home( $f ) ) );
		}
		$md = Report::consolidated( $reports );
		if ( opt( 'out' ) ) {
			write_file( expand_home( (string) opt( 'out' ) ), $md );
		}
		echo $md;
		return 0;
	}
	throw new Fail( "unknown: report $sub (write|collect)", EXIT_USAGE );
}

function cmd_handoff( array $pos ): int {
	$sub = $pos[1] ?? 'write';
	if ( $sub === 'write' ) {
		$cfg = Config::load();
		$b   = read_json( req( 'build-json' ) );
		$e   = $cfg->edition( $b['plugin'], $b['edition'] );
		$cl  = opt( 'changelog' ) ? (string) file_get_contents( (string) opt( 'changelog' ) ) : null;
		$nt  = opt( 'notes' ) ? (string) file_get_contents( (string) opt( 'notes' ) ) : null;
		$cs  = opt( 'changes-since' ) ? (string) file_get_contents( (string) opt( 'changes-since' ) ) : null;
		$md  = Handoff::edition( $cfg, $e, $b, $cl, $nt, $cs );
		$out = opt( 'out', dirname( $b['zip']['path'] ) . '/HANDOFF.md' );
		write_file( (string) $out, $md );
		echo $md;
		return 0;
	}
	if ( $sub === 'combine' ) {
		$items = [];
		foreach ( opt_list( 'builds' ) as $f ) {
			$b       = read_json( expand_home( $f ) );
			$hp      = dirname( $b['zip']['path'] ) . '/HANDOFF.md';
			$items[] = [ 'plugin' => $b['plugin'], 'edition' => $b['edition'], 'target' => $b['target'], 'version' => $b['version'], 'zip' => $b['zip']['file'], 'sha256' => $b['zip']['sha256'], 'handoff_path' => $hp, 'handoff_name' => 'HANDOFF.md' ];
		}
		$date = opt( 'date', gmdate( 'Y-m-d' ) );
		$md   = Handoff::combined( $items, (string) $date );
		write_file( expand_home( req( 'out' ) ), $md );
		echo $md;
		return 0;
	}
	throw new Fail( "unknown: handoff $sub (write|combine)", EXIT_USAGE );
}
