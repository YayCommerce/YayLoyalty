<?php
/**
 * Loads and validates .claude/release.config.json and the user-level registry
 * (~/.config/yayrelease/registry.json), and resolves a plugin edition.
 *
 * The config is repo-specific and committed identically on every long-lived edition dev branch.
 * Lookup order: --config=<file>; <cwd worktree>/.claude/release.config.json; the main checkout's
 * copy; the registry's config_ref branch via `git show` (for repos whose checkout is on a branch
 * that predates the kit). Shape and field meanings: .claude/release-kit/reference/config-reference.md.
 */

declare(strict_types=1);

namespace YR;

final class Config {
	public string $repo;
	public string $file;
	public array $data;

	public const TARGETS = [ 'wporg', 'edd', 'woo' ];

	public function __construct( string $repo, string $file, array $data ) {
		$this->repo = $repo;
		$this->file = $file;
		$this->data = $data;
	}

	/** Load the config for the current command (see lookup order above). */
	public static function load( bool $required = true ): ?self {
		$repo = self::repo_from_options();
		if ( $repo === null ) {
			if ( $required ) {
				throw new Fail( 'not inside a git repo; pass --repo=<path> or --plugin=<key> from the registry', EXIT_NOT_CONFIG );
			}
			return null;
		}
		$explicit = opt( 'config' );
		$cands    = $explicit ? [ $explicit ] : [];
		$cwd_top  = git_try( getcwd() ?: '.', [ 'rev-parse', '--show-toplevel' ] );
		if ( ! $explicit && $cwd_top && main_repo_of( $cwd_top ) === $repo ) {
			$cands[] = $cwd_top . '/.claude/release.config.json';
		}
		$cands[] = $repo . '/.claude/release.config.json';
		foreach ( $cands as $f ) {
			if ( is_file( $f ) ) {
				$cfg = new self( $repo, $f, read_json( $f ) );
				$cfg->validate();
				return $cfg;
			}
		}
		// Fall back to the committed copy on the registry's config_ref.
		$reg = Registry::load();
		foreach ( $reg->data['plugins'] ?? [] as $entry ) {
			if ( ( $entry['repo'] ?? '' ) === $repo && ! empty( $entry['config_ref'] ) ) {
				$raw = git_show( $repo, $entry['config_ref'], '.claude/release.config.json' );
				if ( $raw !== null ) {
					$data = json_decode( $raw, true );
					if ( is_array( $data ) ) {
						$cfg = new self( $repo, $entry['config_ref'] . ':.claude/release.config.json', $data );
						$cfg->validate();
						return $cfg;
					}
				}
			}
		}
		if ( $required ) {
			throw new Fail( "no .claude/release.config.json for $repo. Run the yayinit skill first.", EXIT_NOT_CONFIG );
		}
		return null;
	}

	/** --repo, else the plugin's registry entry, else the git repo around cwd. Always the main checkout. */
	public static function repo_from_options(): ?string {
		$repo = opt( 'repo' );
		if ( $repo !== null ) {
			$repo = expand_home( $repo );
			return main_repo_of( $repo ) ?? $repo;
		}
		$plugin = opt( 'plugin' );
		if ( $plugin !== null ) {
			$entry = Registry::load()->data['plugins'][ $plugin ] ?? null;
			if ( $entry ) {
				return expand_home( $entry['repo'] );
			}
		}
		return main_repo_of( getcwd() ?: '.' );
	}

	public function settings(): array {
		$s = $this->data['settings'] ?? [];
		return $s + [
			'dist'          => 'dist',
			'size_jump_pct' => 25,
			'remote'        => 'origin',
		];
	}

	public function dist_root(): string {
		$d = $this->settings()['dist'];
		return $d[0] === '/' || $d[0] === '~' ? expand_home( $d ) : $this->repo . '/' . $d;
	}

	public function dist_dir( string $plugin, string $edition ): string {
		return $this->dist_root() . '/' . $plugin . '/' . $edition;
	}

	public function plugins(): array {
		return array_keys( $this->data['plugins'] ?? [] );
	}

	public function plugin( string $plugin ): array {
		$p = $this->data['plugins'][ $plugin ] ?? null;
		if ( ! is_array( $p ) ) {
			throw new Fail( "plugin '$plugin' is not in {$this->file}. Configured: " . implode( ', ', $this->plugins() ), EXIT_NOT_CONFIG );
		}
		return $p;
	}

	/** Edition config with defaults filled in. */
	public function edition( string $plugin, string $edition ): array {
		$p = $this->plugin( $plugin );
		$e = $p['editions'][ $edition ] ?? null;
		if ( ! is_array( $e ) ) {
			throw new Fail( "edition '$edition' is not configured for $plugin. Editions: " . implode( ', ', array_keys( $p['editions'] ?? [] ) ), EXIT_NOT_CONFIG );
		}
		$e['key']          = $edition;
		$e['plugin']       = $plugin;
		$e['branch_group'] = $e['branch_group'] ?? $e['dev_branch'];
		$e['tag_format']   = $e['tag_format'] ?? ( $p['tag_format'] ?? '{plugin}-{edition}/v{version}' );
		$e['shared_code']  = $e['shared_code'] ?? ( $p['shared_code'] ?? [] );
		$e['support_log']  = $e['support_log'] ?? "support/$plugin-$edition.md";
		$e['zip_rules']    = ( $e['zip_rules'] ?? [] ) + [ 'must_not_contain' => [], 'must_contain' => [], 'allow' => [] ];
		$e['plugin_check'] = $e['plugin_check'] ?? ( $e['target'] === 'wporg' );
		return $e;
	}

	/** Resolve --plugin/--edition. With $allow_all, a missing edition means "every edition". */
	public function resolve( ?string $plugin, ?string $edition, bool $allow_all = false ): array {
		$plugins = $this->plugins();
		if ( $plugin === null ) {
			if ( count( $plugins ) !== 1 ) {
				throw new Fail( 'this repo configures several plugins; pass --plugin=<' . implode( '|', $plugins ) . '>', EXIT_USAGE );
			}
			$plugin = $plugins[0];
		}
		$editions = array_keys( $this->plugin( $plugin )['editions'] ?? [] );
		if ( $edition === null || $edition === '' ) {
			if ( count( $editions ) === 1 ) {
				return [ $plugin, [ $editions[0] ] ];
			}
			if ( $allow_all ) {
				return [ $plugin, $editions ];
			}
			throw new Fail( "$plugin has several editions (" . implode( ', ', $editions ) . '). Pass --edition=<one>; the kit never guesses.', EXIT_USAGE );
		}
		$this->edition( $plugin, $edition );
		return [ $plugin, [ $edition ] ];
	}

	public function editions_in_group( string $plugin, string $group ): array {
		$out = [];
		foreach ( array_keys( $this->plugin( $plugin )['editions'] ?? [] ) as $k ) {
			if ( $this->edition( $plugin, $k )['branch_group'] === $group ) {
				$out[] = $k;
			}
		}
		return $out;
	}

	public function siblings( string $plugin, string $edition ): array {
		return array_values( array_diff( array_keys( $this->plugin( $plugin )['editions'] ?? [] ), [ $edition ] ) );
	}

	/** Every dev and released branch of every edition: the kit never moves these without approval. */
	public function long_lived_branches(): array {
		$out = [];
		foreach ( $this->plugins() as $p ) {
			foreach ( array_keys( $this->plugin( $p )['editions'] ?? [] ) as $k ) {
				$e     = $this->edition( $p, $k );
				$out[] = $e['dev_branch'];
				if ( ! empty( $e['released_branch'] ) ) {
					$out[] = $e['released_branch'];
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	public function tag_name( string $plugin, string $edition, string $version ): string {
		$e = $this->edition( $plugin, $edition );
		return strtr( $e['tag_format'], [ '{plugin}' => $plugin, '{edition}' => $edition, '{version}' => $version ] );
	}

	/** Tag name up to the version, used to list an edition's tags. */
	public function tag_prefix( string $plugin, string $edition ): string {
		$fmt = $this->edition( $plugin, $edition )['tag_format'];
		$pos = strpos( $fmt, '{version}' );
		return strtr( substr( $fmt, 0, (int) $pos ), [ '{plugin}' => $plugin, '{edition}' => $edition ] );
	}

	/** Name stem for zips and branches: "<plugin>-<edition>", or "<plugin>" when the config omits it. */
	public function stem( string $plugin, string $edition ): string {
		$p = $this->plugin( $plugin );
		if ( ! empty( $p['omit_edition_suffix'] ) && count( $p['editions'] ?? [] ) === 1 ) {
			return $plugin;
		}
		return $plugin . '-' . $edition;
	}

	/** Release branch name. Editions that share a dev branch share one release branch. */
	public function release_branch( string $plugin, string $edition, string $version ): string {
		$e     = $this->edition( $plugin, $edition );
		$group = $this->editions_in_group( $plugin, $e['branch_group'] );
		$stem  = count( $group ) > 1 ? ( $this->plugin( $plugin )['group_names'][ $e['branch_group'] ] ?? $plugin ) : $this->stem( $plugin, $edition );
		return "release/$stem-$version";
	}

	public function support_branch( string $plugin, string $edition, string $ticket ): string {
		return 'support/' . $this->stem( $plugin, $edition ) . '-' . preg_replace( '/[^A-Za-z0-9._-]+/', '-', $ticket );
	}

	/** @throws Fail listing every problem at once. */
	public function validate(): void {
		$errs = [];
		$d    = $this->data;
		if ( ( $d['schema'] ?? null ) !== 1 ) {
			$errs[] = 'schema must be 1';
		}
		if ( empty( $d['plugins'] ) || ! is_array( $d['plugins'] ) ) {
			$errs[] = 'plugins must be a non-empty object';
		}
		foreach ( $d['plugins'] ?? [] as $pk => $p ) {
			if ( ! preg_match( '/^[a-z0-9][a-z0-9-]*$/', (string) $pk ) ) {
				$errs[] = "plugin key '$pk' must be lowercase letters, digits and dashes";
			}
			$policy = $p['version_policy'] ?? null;
			if ( ! in_array( $policy, [ 'shared', 'independent' ], true ) ) {
				$errs[] = "$pk.version_policy must be shared or independent";
			}
			$by_branch = [];
			foreach ( $p['editions'] ?? [] as $ek => $e ) {
				$where = "$pk.editions.$ek";
				if ( ! preg_match( '/^[a-z0-9][a-z0-9-]*$/', (string) $ek ) ) {
					$errs[] = "$where: edition key must be lowercase letters, digits and dashes";
				}
				foreach ( [ 'dev_branch', 'target', 'slug', 'main_file' ] as $k ) {
					if ( empty( $e[ $k ] ) ) {
						$errs[] = "$where.$k is required";
					}
				}
				if ( ! empty( $e['target'] ) && ! in_array( $e['target'], self::TARGETS, true ) ) {
					$errs[] = "$where.target must be one of " . implode( ', ', self::TARGETS );
				}
				if ( empty( $e['version_files'] ) || ! is_array( $e['version_files'] ) ) {
					$errs[] = "$where.version_files must list at least one {path, pattern}";
				}
				foreach ( $e['version_files'] ?? [] as $i => $vf ) {
					if ( empty( $vf['path'] ) || empty( $vf['pattern'] ) ) {
						$errs[] = "$where.version_files[$i] needs path and pattern";
					} elseif ( @preg_match( rx( $vf['pattern'] ), '' ) === false ) {
						$errs[] = "$where.version_files[$i].pattern is not a valid regex";
					} elseif ( strpos( $vf['pattern'], '(?<v>' ) === false ) {
						$errs[] = "$where.version_files[$i].pattern must capture the version as (?<v>...)";
					}
				}
				if ( empty( $e['build']['cmd'] ) || empty( $e['build']['output'] ) ) {
					$errs[] = "$where.build needs cmd and output";
				}
				$fmt = $e['tag_format'] ?? ( $p['tag_format'] ?? '{plugin}-{edition}/v{version}' );
				if ( strpos( $fmt, '{version}' ) === false ) {
					$errs[] = "$where.tag_format must contain {version}";
				}
				$by_branch[ $e['dev_branch'] ?? '' ][] = $ek;
			}
			foreach ( $by_branch as $branch => $eds ) {
				if ( count( $eds ) > 1 && $policy === 'independent' ) {
					$errs[] = "$pk: editions " . implode( ', ', $eds ) . " share dev branch $branch, so version_policy must be shared";
				}
			}
		}
		if ( $errs ) {
			throw new Fail( "invalid {$this->file}:\n  - " . implode( "\n  - ", $errs ), EXIT_NOT_CONFIG );
		}
	}
}

/** ~/.config/yayrelease/registry.json: where each plugin's repo is, the shared-code pin, run settings. */
final class Registry {
	public string $file;
	public array $data;

	private static ?self $cache = null;

	public function __construct( string $file, array $data ) {
		$this->file = $file;
		$this->data = $data;
	}

	public static function path(): string {
		return expand_home( getenv( 'YR_REGISTRY' ) ?: '~/.config/yayrelease/registry.json' );
	}

	public static function load(): self {
		if ( self::$cache ) {
			return self::$cache;
		}
		$f    = self::path();
		$data = is_file( $f ) ? read_json( $f ) : [];
		$data += [ 'schema' => 1, 'settings' => [], 'plugins' => [], 'shared_code' => [] ];
		$data['settings'] += [
			'concurrency'   => 3,
			'worktree_root' => '~/.yayrelease/worktrees',
			'cache_root'    => '~/.yayrelease/cache',
		];
		return self::$cache = new self( $f, $data );
	}

	public function save( string $why ): void {
		write_json( $this->file, $this->data, $why );
	}

	public function setting( string $k ): string {
		return expand_home( (string) $this->data['settings'][ $k ] );
	}

	public function pin( string $package ): ?array {
		return $this->data['shared_code'][ $package ]['pin'] ?? null;
	}
}

function cmd_config( array $pos ): int {
	$sub = $pos[1] ?? 'get';
	if ( $sub === 'validate' ) {
		$cfg = Config::load();
		say( "ok: {$cfg->file} is valid (" . count( $cfg->plugins() ) . ' plugin(s))' );
		return 0;
	}
	$cfg = Config::load();
	if ( $sub === 'get' ) {
		out_json( [ 'repo' => $cfg->repo, 'file' => $cfg->file, 'config' => $cfg->data ] );
		return 0;
	}
	if ( $sub === 'resolve' ) {
		[ $plugin, $eds ] = $cfg->resolve( opt( 'plugin' ), opt( 'edition' ), flag( 'all' ) );
		$out = [];
		foreach ( $eds as $k ) {
			$out[ $k ] = $cfg->edition( $plugin, $k );
		}
		out_json( [ 'repo' => $cfg->repo, 'plugin' => $plugin, 'editions' => $out ] );
		return 0;
	}
	if ( $sub === 'env' ) {
		// Shell variables for one edition, for lib/sh/*.sh to eval.
		[ $plugin, $eds ] = $cfg->resolve( opt( 'plugin' ), opt( 'edition' ) );
		$e   = $cfg->edition( $plugin, $eds[0] );
		$reg = Registry::load();
		$vars = [
			'YR_REPO'          => $cfg->repo,
			'YR_CONFIG_FILE'   => $cfg->file,
			'YR_PLUGIN'        => $plugin,
			'YR_EDITION'       => $e['key'],
			'YR_STEM'          => $cfg->stem( $plugin, $e['key'] ),
			'YR_LABEL'         => $e['label'] ?? ( $plugin . ' ' . $e['key'] ),
			'YR_TARGET'        => $e['target'],
			'YR_SLUG'          => $e['slug'],
			'YR_MAIN_FILE'     => $e['main_file'],
			'YR_DEV_BRANCH'    => $e['dev_branch'],
			'YR_RELEASED_BRANCH' => $e['released_branch'] ?? '',
			'YR_BRANCH_GROUP'  => $e['branch_group'],
			'YR_GROUP_EDITIONS' => implode( ',', $cfg->editions_in_group( $plugin, $e['branch_group'] ) ),
			'YR_TAG_FORMAT'    => $e['tag_format'],
			'YR_PREPARE_CMD'   => $e['prepare'] ?? '',
			'YR_NODE'          => (string) ( $e['node'] ?? ( $cfg->plugin( $plugin )['node'] ?? '' ) ),
			'YR_BUILD_CMD'     => $e['build']['cmd'],
			'YR_BUILD_OUTPUT'  => $e['build']['output'],
			'YR_SHARED_PKGS'   => implode( ',', array_map( static fn( $s ) => $s['package'], $e['shared_code'] ) ),
			'YR_DIST_DIR'      => $cfg->dist_dir( $plugin, $e['key'] ),
			'YR_DIST_ROOT'     => $cfg->dist_root(),
			'YR_REMOTE'        => $cfg->settings()['remote'],
			'YR_WORKTREE_ROOT' => $reg->setting( 'worktree_root' ),
			'YR_CACHE_ROOT'    => $reg->setting( 'cache_root' ),
			'YR_LONG_LIVED'    => implode( ',', $cfg->long_lived_branches() ),
		];
		$i = 0;
		foreach ( ( $e['build']['env'] ?? [] ) as $k => $v ) {
			$vars[ 'YR_BUILD_ENV_' . $i ] = $k . '=' . $v;
			$i++;
		}
		$vars['YR_BUILD_ENV_COUNT'] = (string) $i;
		echo shell_env( $vars );
		return 0;
	}
	if ( $sub === 'release-branch' ) {
		[ $plugin, $eds ] = $cfg->resolve( opt( 'plugin' ), opt( 'edition' ) );
		echo $cfg->release_branch( $plugin, $eds[0], req( 'version' ) ) . "\n";
		return 0;
	}
	if ( $sub === 'long-lived' ) {
		echo implode( "\n", $cfg->long_lived_branches() ) . "\n";
		return 0;
	}
	throw new Fail( "unknown: config $sub (get|resolve|validate|env|release-branch|long-lived)", EXIT_USAGE );
}

function cmd_registry( array $pos ): int {
	$sub = $pos[1] ?? 'list';
	$reg = Registry::load();
	if ( $sub === 'list' ) {
		out_json( $reg->data );
		return 0;
	}
	if ( $sub === 'add' ) {
		$plugin = req( 'plugin' );
		$repo   = expand_home( req( 'repo' ) );
		if ( main_repo_of( $repo ) !== $repo ) {
			throw new Fail( "$repo is not the main checkout of a git repo", EXIT_USAGE );
		}
		$reg->data['plugins'][ $plugin ] = [
			'repo'       => $repo,
			'config_ref' => req( 'config-ref' ),
			'added'      => now_iso(),
		];
		$reg->save( "register $plugin" );
		say( ( Ctx::$dry ? '[dry-run] ' : '' ) . "registered $plugin → $repo (config read from " . opt( 'config-ref' ) . ' when the checkout lacks it)' );
		return 0;
	}
	if ( $sub === 'set' ) {
		$k = req( 'key' );
		if ( ! in_array( $k, [ 'concurrency', 'worktree_root', 'cache_root', 'plugin_check_path' ], true ) ) {
			throw new Fail( 'settable keys: concurrency, worktree_root, cache_root, plugin_check_path', EXIT_USAGE );
		}
		$reg->data['settings'][ $k ] = $k === 'concurrency' ? max( 1, (int) req( 'value' ) ) : req( 'value' );
		$reg->save( "set $k" );
		return 0;
	}
	throw new Fail( "unknown: registry $sub (list|add|set)", EXIT_USAGE );
}
