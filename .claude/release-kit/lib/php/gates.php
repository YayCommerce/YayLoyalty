<?php
/**
 * QA gates for one built zip. Every gate returns pass, fail, warn or skip with details; any fail
 * stops the build (lib/sh/build.sh keeps the zip in dist/.staging for inspection).
 *
 * Gates: structure, php_lint, phpcs, plugin_check, versions, headers, contents, edition_rules,
 * shared_code, size, and the repo's own validators (extra_gates). What each checks and how to
 * configure it: .claude/release-kit/reference/gates.md.
 */

declare(strict_types=1);

namespace YR;

final class Gates {
	/** Paths that never belong in a customer zip, unless an edition's zip_rules.allow lists them. */
	public const FORBIDDEN = [
		'node_modules'   => '#(^|/)node_modules/#',
		'git'            => '#(^|/)\.(git|svn|hg)(/|$)#',
		'github'         => '#(^|/)\.github/#',
		'tests'          => '#(^|/)(tests?|__tests__|e2e|spec)/#',
		'phpunit'        => '#(^|/)phpunit(\.xml)?(\.dist)?$#',
		'dev_configs'    => '#(^|/)(\.eslintrc[^/]*|\.prettierrc[^/]*|\.editorconfig|\.stylelintrc[^/]*|\.phpcs\.xml(\.dist)?|phpcs\.xml(\.dist)?|phpstan\.neon(\.dist)?|vite\.config\.[jt]s|tsconfig\.json|\.nvmrc|\.npmrc|\.env[^/]*|\.distignore|\.gitignore|\.gitattributes|\.DS_Store|Thumbs\.db)$#',
		'claude'         => '#(^|/)(\.claude/|CLAUDE(\.local)?\.md$|AGENTS\.md$|plans/|\.(?:open)code/|\.qoder/)#',
		'source_maps'    => '#\.map$#',
		'nested_zips'    => '#\.zip$#',
		'lockfiles'      => '#(^|/)(package-lock\.json|yarn\.lock|pnpm-lock\.yaml)$#',
		'vendor_bin'     => '#(^|/)vendor/bin/#',
	];

	private array $results = [];
	private string $tmp;
	private string $dir;

	public function __construct(
		private Config $cfg,
		private array $e,
		private string $zip,
		private ?string $raw_zip,
		private ?string $worktree,
		private string $version,
		private array $pins
	) {
		$this->tmp = sys_get_temp_dir() . '/yr-gates-' . getmypid() . '-' . bin2hex( random_bytes( 3 ) );
		[ $code, , $err ] = run( [ 'unzip', '-q', $zip, '-d', $this->tmp ] );
		if ( $code !== 0 ) {
			throw new Fail( "cannot unzip $zip: $err" );
		}
		$this->dir = $this->tmp . '/' . $e['slug'];
	}

	public function __destruct() {
		if ( is_dir( $this->tmp ) ) {
			run( [ 'rm', '-rf', $this->tmp ] );
		}
	}

	private function add( string $id, string $status, string $summary, array $details = [] ): void {
		$this->results[] = [ 'id' => $id, 'status' => $status, 'summary' => $summary, 'details' => array_slice( $details, 0, 50 ) ];
	}

	private function sev( string $gate, string $default ): string {
		return $this->e['gate_severity'][ $gate ] ?? $default;
	}

	public function run_all( array $skip ): array {
		$z = Builds::inspect( $this->zip );
		foreach ( [ 'structure', 'php_lint', 'phpcs', 'plugin_check', 'versions', 'headers', 'contents', 'edition_rules', 'shared_code', 'size', 'extra' ] as $g ) {
			if ( in_array( $g, $skip, true ) ) {
				$this->add( $g, 'skip', 'skipped by --skip' );
				continue;
			}
			$this->{ 'gate_' . $g }( $z );
		}
		$sum = [ 'pass' => 0, 'fail' => 0, 'warn' => 0, 'skip' => 0 ];
		foreach ( $this->results as $r ) {
			$sum[ $r['status'] ]++;
		}
		return [ 'ok' => $sum['fail'] === 0, 'summary' => $sum, 'zip' => basename( $this->zip ), 'version' => $this->version, 'date' => now_iso(), 'results' => $this->results ];
	}

	private function gate_structure( array $z ): void {
		$bad = [];
		if ( $z['roots'] !== [ $this->e['slug'] ] ) {
			$bad[] = 'top-level entries: ' . implode( ', ', $z['roots'] ) . " (expected only '{$this->e['slug']}/')";
		}
		if ( ! is_file( "{$this->dir}/{$this->e['main_file']}" ) ) {
			$bad[] = "main file {$this->e['slug']}/{$this->e['main_file']} missing";
		}
		$info = is_file( "{$this->dir}/build-info.json" ) ? json_decode( (string) file_get_contents( "{$this->dir}/build-info.json" ), true ) : null;
		if ( ! $info ) {
			$bad[] = 'build-info.json missing';
		} elseif ( ( $info['version'] ?? '' ) !== $this->version ) {
			$bad[] = "build-info.json version {$info['version']} ≠ {$this->version}";
		}
		$this->add( 'structure', $bad ? 'fail' : 'pass', $bad ? 'zip layout is wrong' : "one folder '{$this->e['slug']}/', main file and build-info.json present", $bad );
	}

	private function gate_php_lint( array $z ): void {
		[ $code, $out ] = run( [ 'bash', '-c', 'find "$1" -name "*.php" -print0 | xargs -0 -n 25 -P 4 "$2" -d display_errors=1 -d error_reporting=-1 -l 2>&1 | grep -v "^No syntax errors detected" || true', 'lint', $this->dir, PHP_BINARY ] );
		$errs  = array_values( array_filter( explode( "\n", trim( $out ) ), static fn( $l ) => trim( $l ) !== '' && stripos( $l, 'error' ) !== false ) );
		$count = (int) trim( run( [ 'bash', '-c', 'find "$1" -name "*.php" | wc -l', 'c', $this->dir ] )[1] );
		$errs  = array_map( fn( $l ) => str_replace( $this->tmp . '/', '', $l ), $errs );
		$this->add( 'php_lint', $errs ? 'fail' : 'pass', $errs ? count( $errs ) . " PHP file(s) do not parse (PHP " . PHP_VERSION . ')' : "$count PHP files parse (PHP " . PHP_VERSION . '; PHP 7.4 syntax is phpcs/PHPCompatibility\'s job)', $errs );
	}

	private function gate_phpcs( array $z ): void {
		$cmd = $this->e['phpcs']['cmd'] ?? null;
		if ( ! $cmd ) {
			$this->add( 'phpcs', 'skip', 'no phpcs command configured for this edition' );
			return;
		}
		$cmd = strtr( $cmd, [ '{dir}' => escapeshellarg( $this->dir ) ] );
		[ $code, $out, $err ] = run( [ 'bash', '-c', $cmd ], $this->worktree ?? $this->cfg->repo );
		$sev = $this->sev( 'phpcs', $this->e['phpcs']['severity'] ?? 'warn' );
		$this->add( 'phpcs', $code === 0 ? 'pass' : $sev, $code === 0 ? 'phpcs clean' : "phpcs reported problems (severity: $sev)", array_slice( explode( "\n", trim( $out . "\n" . $err ) ), -30 ) );
	}

	private function gate_plugin_check( array $z ): void {
		if ( ! $this->e['plugin_check'] ) {
			$this->add( 'plugin_check', 'skip', 'Plugin Check is off for this edition (on by default for wporg)' );
			return;
		}
		$json = $this->tmp . '/plugin-check.json';
		[ $code, $out, $err ] = run( [ 'bash', kit_dir() . '/lib/sh/plugin-check.sh', '--dir=' . $this->dir, '--slug=' . $this->e['slug'], '--out=' . $json ] );
		$detail = array_slice( explode( "\n", trim( $out . "\n" . $err ) ), -25 );
		if ( $code === 2 ) {
			$this->add( 'plugin_check', 'warn', 'Plugin Check could not run here: ' . trim( (string) end( $detail ) ), $detail );
		} elseif ( $code === 0 ) {
			$this->add( 'plugin_check', 'pass', 'Plugin Check: no errors in the plugin_repo category', $detail );
		} else {
			$this->add( 'plugin_check', $this->sev( 'plugin_check', 'fail' ), 'Plugin Check found errors that block a wp.org upload', $detail );
		}
	}

	private function gate_versions( array $z ): void {
		$locs = Version::read( $this->cfg, $this->e, null, $this->dir );
		$bad  = [];
		$seen = [];
		foreach ( $locs as $l ) {
			if ( ! $l['present'] ) {
				continue; // Not shipped (e.g. README.md); the source check covers it.
			}
			$seen[] = $l['path'];
			if ( $l['value'] === null ) {
				$bad[] = "{$l['path']}: version pattern not found";
			} elseif ( $l['value'] !== $this->version ) {
				$bad[] = "{$l['path']}: {$l['value']} ≠ {$this->version}";
			}
		}
		$this->add( 'versions', $bad ? 'fail' : 'pass', $bad ? 'version numbers disagree inside the zip' : "all shipped locations say {$this->version} (" . implode( ', ', array_unique( $seen ) ) . ')', $bad );
	}

	/** Read `Key: value` header lines from a file's first 8 KB. */
	private static function headers( string $file ): array {
		if ( ! is_file( $file ) ) {
			return [];
		}
		$h = [];
		$s = (string) file_get_contents( $file, false, null, 0, 8192 );
		if ( preg_match_all( '/^[ \t\/*#@]*([A-Za-z][A-Za-z ]+?):[ \t]*(.+)$/m', $s, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $x ) {
				$k = strtolower( trim( $x[1] ) );
				if ( ! isset( $h[ $k ] ) ) {
					$h[ $k ] = trim( $x[2] );
				}
			}
		}
		return $h;
	}

	private function gate_headers( array $z ): void {
		$wporg  = $this->e['target'] === 'wporg';
		$readme = "{$this->dir}/readme.txt";
		$rh     = self::headers( $readme );
		$mh     = self::headers( "{$this->dir}/{$this->e['main_file']}" );
		$need   = $this->e['required_headers'] ?? [ 'Tested up to', 'Requires PHP', 'Requires at least' ];
		if ( isset( $mh['wc requires at least'] ) || isset( $mh['requires plugins'] ) && stripos( $mh['requires plugins'], 'woocommerce' ) !== false ) {
			$need[] = 'WC tested up to';
		}
		$bad = [];
		if ( $wporg && ! is_file( $readme ) ) {
			$bad[] = 'readme.txt missing (required for WordPress.org)';
		}
		foreach ( array_unique( $need ) as $k ) {
			$lk = strtolower( $k );
			if ( empty( $rh[ $lk ] ) && empty( $mh[ $lk ] ) ) {
				$bad[] = "'$k' missing from readme.txt and the plugin header";
			}
		}
		if ( $wporg && is_file( $readme ) && ( $rh['stable tag'] ?? '' ) !== $this->version ) {
			$bad[] = "readme.txt Stable tag is '" . ( $rh['stable tag'] ?? '' ) . "', expected {$this->version}";
		}
		if ( ( $mh['version'] ?? '' ) !== $this->version ) {
			$bad[] = "plugin header Version is '" . ( $mh['version'] ?? '' ) . "'";
		}
		$found = [];
		foreach ( array_unique( $need ) as $k ) {
			$found[] = "$k: " . ( $rh[ strtolower( $k ) ] ?? $mh[ strtolower( $k ) ] ?? '—' );
		}
		$status = $bad ? ( $wporg ? 'fail' : $this->sev( 'headers', 'warn' ) ) : 'pass';
		$this->add( 'headers', $status, $bad ? 'required headers missing or wrong' : 'required headers present', array_merge( $bad, $found ) );
	}

	private function gate_contents( array $z ): void {
		$allow = $this->e['zip_rules']['allow'];
		$hits  = [];
		foreach ( $z['entries'] as $path ) {
			$rel = substr( $path, strlen( $this->e['slug'] ) + 1 );
			if ( $rel === '' || $rel === false ) {
				continue;
			}
			foreach ( self::FORBIDDEN as $why => $re ) {
				if ( preg_match( $re, $rel ) && ! self::allowed( $rel, $why, $allow ) ) {
					$hits[] = "$why: $rel";
					break;
				}
			}
		}
		// Collapse a forbidden folder to one line per folder.
		$lines = [];
		foreach ( $hits as $h ) {
			[ $why, $rel ] = explode( ': ', $h, 2 );
			$key           = $why . ': ' . ( preg_match( '#^(.*?(node_modules|tests?|\.git|\.github|vendor/bin|plans|\.claude)/)#', $rel, $m ) ? $m[1] . '…' : $rel );
			$lines[ $key ] = true;
		}
		$this->add( 'contents', $hits ? 'fail' : 'pass', $hits ? count( $hits ) . ' file(s) that should not ship' : 'no dev files, VCS folders, tests, source maps or lockfiles', array_keys( $lines ) );
	}

	private static function allowed( string $rel, string $why, array $allow ): bool {
		foreach ( $allow as $a ) {
			if ( $a === $why || @preg_match( rx( $a ), $rel ) ) {
				return true;
			}
		}
		return false;
	}

	private function gate_edition_rules( array $z ): void {
		$rules = $this->e['zip_rules'];
		$bad   = [];   // [severity, message]
		$rels  = array_map( fn( $p ) => (string) substr( $p, strlen( $this->e['slug'] ) + 1 ), $z['entries'] );
		$why   = static fn( $r ) => isset( $r['why'] ) ? " ({$r['why']})" : '';
		$sev   = fn( $r ) => $r['severity'] ?? 'fail';
		foreach ( $rules['must_not_contain'] as $r ) {
			if ( isset( $r['path'] ) ) {
				foreach ( $rels as $rel ) {
					if ( $rel !== '' && preg_match( rx( $r['path'] ), $rel ) ) {
						$bad[] = [ $sev( $r ), "must not contain {$r['path']}: found $rel" . $why( $r ) ];
						break;
					}
				}
			}
			if ( isset( $r['content'] ) ) {
				foreach ( $this->grep( $r['content'], $r['files'] ?? '\.php$' ) as $f ) {
					$bad[] = [ $sev( $r ), "must not contain text /{$r['content']}/: $f" . $why( $r ) ];
				}
			}
		}
		foreach ( $rules['must_contain'] as $r ) {
			$found = false;
			if ( isset( $r['path'] ) ) {
				foreach ( $rels as $rel ) {
					if ( $rel !== '' && preg_match( rx( $r['path'] ), $rel ) ) {
						$found = true;
						break;
					}
				}
			}
			if ( isset( $r['content'] ) ) {
				$found = (bool) $this->grep( $r['content'], $r['files'] ?? '\.php$' );
			}
			if ( ! $found ) {
				$bad[] = [ $sev( $r ), 'must contain ' . ( $r['path'] ?? "text /{$r['content']}/" ) . ': missing' . $why( $r ) ];
			}
		}
		$n = count( $rules['must_not_contain'] ) + count( $rules['must_contain'] );
		if ( $n === 0 ) {
			$this->add( 'edition_rules', 'warn', 'no edition rules configured (e.g. "Lite must not contain includes/Pro/")' );
			return;
		}
		$fails  = array_filter( $bad, static fn( $b ) => $b[0] === 'fail' );
		$status = $fails ? 'fail' : ( $bad ? 'warn' : 'pass' );
		$lines  = array_map( static fn( $b ) => ( $b[0] === 'fail' ? '' : '(warn) ' ) . $b[1], $bad );
		$this->add( 'edition_rules', $status, $fails ? 'edition boundary broken' : ( $bad ? 'edition rules with severity warn were hit' : "$n edition rule(s) hold" ), $lines );
	}

	/** Files in the unzipped plugin whose content matches $re (ERE), filtered by a path regex. */
	private function grep( string $re, string $files ): array {
		[ , $out ] = run( [ 'bash', '-c', 'cd "$1" && grep -rlE -- "$2" . | grep -E -- "$3" | head -20 || true', 'g', $this->dir, $re, $files ] );
		return array_map( static fn( $f ) => ltrim( $f, './' ), array_values( array_filter( explode( "\n", trim( $out ) ) ) ) );
	}

	private function gate_shared_code( array $z ): void {
		if ( ! $this->e['shared_code'] ) {
			$this->add( 'shared_code', 'skip', 'this edition ships no shared code' );
			return;
		}
		$bad  = [];
		$warn = [];
		$info = [];
		foreach ( $this->e['shared_code'] as $sc ) {
			$pkg  = $sc['package'];
			$pin  = $this->pins[ $pkg ] ?? null;
			$file = "{$this->dir}/" . ( $sc['version_file'] ?? 'vendor-prefixed/src/AdminShell.php' );
			$re   = $sc['version_pattern'] ?? "const\\s+VERSION\\s*=\\s*'(?<v>[^']+)'";
			$have = is_file( $file ) && preg_match( rx( $re ), (string) file_get_contents( $file ), $m ) ? $m['v'] : null;
			$info[] = "$pkg in zip reports " . ( $have ?? 'nothing' ) . ( $pin ? "; pinned {$pin['ref']} ({$pin['short']}, reports {$pin['const']})" : '; no pin' );
			if ( ! $pin ) {
				$bad[] = "$pkg: no pin for this build";
			} elseif ( $have === null ) {
				$bad[] = "$pkg: version not found in " . substr( $file, strlen( $this->tmp ) + 1 );
			} elseif ( $have !== $pin['const'] ) {
				$bad[] = "$pkg: zip has $have but the pin reports {$pin['const']}";
			}
			foreach ( $pin['warnings'] ?? [] as $w ) {
				$warn[] = "$pkg: $w";
			}
		}
		$status = $bad ? 'fail' : ( $warn ? 'warn' : 'pass' );
		$this->add( 'shared_code', $status, $bad ? 'shared code in the zip is not the pinned revision' : ( $warn ? 'pinned revision has a known problem' : 'shared code matches the pin' ), array_merge( $bad, $warn, $info ) );
	}

	private function gate_size( array $z ): void {
		$prev = null;
		foreach ( Builds::all( $this->cfg->dist_dir( $this->e['plugin'], $this->e['key'] ) ) as $b ) {
			if ( ! empty( $b['zip']['size'] ) ) {
				$prev = $b;
				break;
			}
		}
		$mb = round( $z['size'] / 1048576, 2 );
		if ( ! $prev ) {
			$this->add( 'size', 'pass', "{$mb} MB (no previous build to compare)" );
			return;
		}
		$pct  = $prev['zip']['size'] > 0 ? ( $z['size'] - $prev['zip']['size'] ) * 100 / $prev['zip']['size'] : 0;
		$lim  = (float) $this->cfg->settings()['size_jump_pct'];
		$line = sprintf( '%s MB vs %s MB in %s (%+.1f%%)', $mb, round( $prev['zip']['size'] / 1048576, 2 ), $prev['zip']['file'], $pct );
		$this->add( 'size', abs( $pct ) > $lim ? 'warn' : 'pass', abs( $pct ) > $lim ? "size changed more than {$lim}%: $line" : $line );
	}

	private function gate_extra( array $z ): void {
		$extra = $this->e['extra_gates'] ?? [];
		if ( ! $extra ) {
			$this->add( 'extra', 'skip', 'no repo validators configured' );
			return;
		}
		foreach ( $extra as $g ) {
			$id  = 'extra:' . ( $g['id'] ?? 'gate' );
			$zip = ( $g['on'] ?? 'raw' ) === 'raw' && $this->raw_zip ? $this->raw_zip : $this->zip;
			$cmd = strtr( $g['cmd'], [
				'{zip}' => escapeshellarg( $zip ), '{dir}' => escapeshellarg( $this->dir ),
				'{version}' => escapeshellarg( $this->version ), '{worktree}' => escapeshellarg( (string) $this->worktree ),
			] );
			$cwd = $this->worktree && is_dir( $this->worktree ) ? $this->worktree : $this->cfg->repo;
			[ $code, $out, $err ] = run( [ 'bash', '-c', $cmd ], $cwd );
			$this->add( $id, $code === 0 ? 'pass' : $this->sev( $id, 'fail' ), $code === 0 ? ( $g['label'] ?? $g['cmd'] ) . ' passed' : ( $g['label'] ?? $g['cmd'] ) . " failed (exit $code)", array_slice( explode( "\n", trim( $out . "\n" . $err ) ), -25 ) );
		}
	}
}

function cmd_gates( array $pos ): int {
	$cfg = Config::load();
	[ $plugin, $eds ] = $cfg->resolve( opt( 'plugin' ), opt( 'edition' ) );
	$e    = $cfg->edition( $plugin, $eds[0] );
	$pins = opt( 'pins' ) ? read_json( (string) opt( 'pins' ) ) : [];
	$g    = new Gates( $cfg, $e, req( 'zip' ), opt( 'raw-zip' ), opt( 'worktree' ), req( 'version' ), $pins );
	$res  = $g->run_all( opt_list( 'skip' ) );
	$res['plugin']  = $plugin;
	$res['edition'] = $e['key'];
	if ( opt( 'out' ) ) {
		write_json( (string) opt( 'out' ), $res );
	}
	if ( flag( 'json' ) ) {
		out_json( $res );
	} else {
		foreach ( $res['results'] as $r ) {
			say( sprintf( '  %-5s %-16s %s', strtoupper( $r['status'] ), $r['id'], $r['summary'] ) );
			if ( $r['status'] === 'fail' ) {
				foreach ( array_slice( $r['details'], 0, 8 ) as $d ) {
					say( "          · $d" );
				}
			}
		}
		say( sprintf( 'gates: %d pass, %d fail, %d warn, %d skip', $res['summary']['pass'], $res['summary']['fail'], $res['summary']['warn'], $res['summary']['skip'] ) );
	}
	return $res['ok'] ? 0 : EXIT_GATES;
}
