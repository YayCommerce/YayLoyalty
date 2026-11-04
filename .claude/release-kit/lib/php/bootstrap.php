<?php
/**
 * Shared helpers for the release kit's PHP commands: argument parsing, process and git calls,
 * dry-run aware file writes, and JSON I/O. Every other lib file requires this one.
 *
 * Options are always `--key=value`; a bare `--key` is a boolean flag. Positional words are the
 * command path (e.g. `version get`). Errors throw Fail; cli.php turns them into an exit code.
 */

declare(strict_types=1);

namespace YR;

final class Fail extends \RuntimeException {}

final class Ctx {
	/** @var bool Print what would change instead of changing it. */
	public static bool $dry = false;
	/** @var array<string,string|bool> Parsed options of the current command. */
	public static array $opt = [];
}

/** Exit codes shared with the bash side (lib/sh/common.sh). */
const EXIT_FAIL       = 1;
const EXIT_USAGE      = 64;
const EXIT_EXISTS     = 3;
const EXIT_CONFLICT   = 4;
const EXIT_GATES      = 5;
const EXIT_NOT_CONFIG = 6;

/**
 * @param string[] $argv
 * @return array{0: string[], 1: array<string,string|bool>}
 */
function parse_args( array $argv ): array {
	$pos = [];
	$opt = [];
	foreach ( $argv as $a ) {
		if ( strncmp( $a, '--', 2 ) === 0 ) {
			$kv = substr( $a, 2 );
			$eq = strpos( $kv, '=' );
			if ( $eq === false ) {
				$opt[ $kv ] = true;
			} else {
				$opt[ substr( $kv, 0, $eq ) ] = substr( $kv, $eq + 1 );
			}
		} else {
			$pos[] = $a;
		}
	}
	return [ $pos, $opt ];
}

function opt( string $key, ?string $default = null ): ?string {
	$v = Ctx::$opt[ $key ] ?? null;
	if ( $v === null || $v === true ) {
		return $default;
	}
	return (string) $v;
}

function req( string $key ): string {
	$v = opt( $key );
	if ( $v === null || $v === '' ) {
		throw new Fail( "missing required option --$key=<value>", EXIT_USAGE );
	}
	return $v;
}

function flag( string $key ): bool {
	return isset( Ctx::$opt[ $key ] ) && Ctx::$opt[ $key ] !== 'false' && Ctx::$opt[ $key ] !== '0';
}

/** Comma list option → array (empty when absent). */
function opt_list( string $key ): array {
	$v = opt( $key );
	if ( $v === null || $v === '' ) {
		return [];
	}
	return array_values( array_filter( array_map( 'trim', explode( ',', $v ) ), 'strlen' ) );
}

function say( string $msg ): void {
	fwrite( STDERR, $msg . "\n" );
}

function out_json( $data ): void {
	echo json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
}

function expand_home( string $path ): string {
	if ( $path === '~' || strncmp( $path, '~/', 2 ) === 0 ) {
		$home = getenv( 'HOME' ) ?: '';
		return $home . substr( $path, 1 );
	}
	return $path;
}

function now_iso(): string {
	return gmdate( 'Y-m-d\TH:i:s\Z' );
}

function kit_dir(): string {
	return dirname( __DIR__, 2 );
}

function kit_version(): string {
	$f = kit_dir() . '/VERSION';
	return is_file( $f ) ? trim( (string) file_get_contents( $f ) ) : 'unknown';
}

/**
 * Run a command without a shell. Returns [exit code, stdout, stderr]. stdin is closed so
 * nothing can wait on a prompt.
 *
 * @param string[] $cmd
 */
function run( array $cmd, ?string $cwd = null, ?array $env = null ): array {
	$spec = [ 0 => [ 'file', '/dev/null', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ];
	$p    = proc_open( $cmd, $spec, $pipes, $cwd, $env );
	if ( ! is_resource( $p ) ) {
		throw new Fail( 'could not start: ' . implode( ' ', $cmd ) );
	}
	$out = stream_get_contents( $pipes[1] );
	$err = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );
	$code = proc_close( $p );
	return [ $code, (string) $out, (string) $err ];
}

/** git in $repo; throws on failure. */
function git( string $repo, array $args ): string {
	[ $code, $out, $err ] = run( array_merge( [ 'git', '-C', $repo ], $args ) );
	if ( $code !== 0 ) {
		throw new Fail( 'git ' . implode( ' ', $args ) . ' failed: ' . trim( $err ) );
	}
	return rtrim( $out, "\n" );
}

/** git in $repo; null on failure (for probes such as "does this ref exist"). */
function git_try( string $repo, array $args ): ?string {
	[ $code, $out ] = run( array_merge( [ 'git', '-C', $repo ], $args ) );
	return $code === 0 ? rtrim( $out, "\n" ) : null;
}

/** File content at a git ref, or null when the path is absent there. */
function git_show( string $repo, string $ref, string $path ): ?string {
	[ $code, $out ] = run( [ 'git', '-C', $repo, 'show', $ref . ':' . $path ] );
	return $code === 0 ? $out : null;
}

function ref_exists( string $repo, string $ref ): bool {
	return git_try( $repo, [ 'rev-parse', '--verify', '--quiet', $ref . '^{commit}' ] ) !== null;
}

/** The main checkout of the repo that contains $dir (works from inside any worktree). */
function main_repo_of( string $dir ): ?string {
	$common = git_try( $dir, [ 'rev-parse', '--path-format=absolute', '--git-common-dir' ] );
	if ( $common === null ) {
		return null;
	}
	return basename( $common ) === '.git' ? dirname( $common ) : $common;
}

function read_json( string $file ): array {
	if ( ! is_file( $file ) ) {
		throw new Fail( "file not found: $file", EXIT_NOT_CONFIG );
	}
	$data = json_decode( (string) file_get_contents( $file ), true );
	if ( ! is_array( $data ) ) {
		throw new Fail( "invalid JSON in $file: " . json_last_error_msg() );
	}
	return $data;
}

/** Write a file unless dry-run; in dry-run, report what would be written. */
function write_file( string $file, string $content, string $why = '' ): void {
	if ( Ctx::$dry ) {
		say( "[dry-run] would write $file" . ( $why ? " ($why)" : '' ) . ' (' . strlen( $content ) . ' bytes)' );
		return;
	}
	$dir = dirname( $file );
	if ( ! is_dir( $dir ) && ! mkdir( $dir, 0777, true ) && ! is_dir( $dir ) ) {
		throw new Fail( "cannot create $dir" );
	}
	$tmp = $file . '.tmp' . getmypid();
	if ( file_put_contents( $tmp, $content ) === false || ! rename( $tmp, $file ) ) {
		throw new Fail( "cannot write $file" );
	}
}

function write_json( string $file, $data, string $why = '' ): void {
	write_file( $file, json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n", $why );
}

/** Shell-quoted KEY='value' lines that bash can eval. */
function shell_env( array $vars ): string {
	$lines = [];
	foreach ( $vars as $k => $v ) {
		if ( ! preg_match( '/^[A-Z_][A-Z0-9_]*$/', (string) $k ) ) {
			throw new Fail( "invalid env name: $k" );
		}
		$lines[] = $k . '=' . escapeshellarg( is_bool( $v ) ? ( $v ? '1' : '0' ) : (string) $v );
	}
	return implode( "\n", $lines ) . "\n";
}

/** Simple {{key}} template fill. Unknown keys stay visible so a gap is noticed. */
function fill( string $tpl, array $vars ): string {
	return preg_replace_callback(
		'/\{\{\s*([a-z0-9_.]+)\s*\}\}/i',
		static function ( $m ) use ( $vars ) {
			return array_key_exists( $m[1], $vars ) ? (string) $vars[ $m[1] ] : $m[0];
		},
		$tpl
	);
}

/** Config regex (no delimiters, multiline) → PCRE with delimiters. */
function rx( string $pattern ): string {
	return '~' . str_replace( '~', '\~', $pattern ) . '~m';
}
