<?php
/**
 * Entry point for the kit's PHP commands; bin/yr routes here. `php cli.php <command> [sub] --opts`.
 * Exit codes: 0 ok, 1 failure, 3 already exists, 4 conflict, 5 gates failed, 6 not configured, 64 usage.
 */

declare(strict_types=1);

namespace YR;

if ( PHP_VERSION_ID < 80000 ) {
	fwrite( STDERR, "yr: the release kit needs PHP 8.0+ on the command line (found " . PHP_VERSION . ")\n" );
	exit( 1 );
}

foreach ( [ 'bootstrap', 'config', 'commits', 'version', 'builds', 'gates', 'changelog', 'support', 'status', 'outputs', 'detect', 'conflicts' ] as $f ) {
	require_once __DIR__ . "/$f.php";
}

[ $pos, $opt ] = parse_args( array_slice( $argv, 1 ) );
Ctx::$opt = $opt;
Ctx::$dry = flag( 'dry-run' );

$map = [
	'config' => 'cmd_config', 'registry' => 'cmd_registry', 'version' => 'cmd_version', 'commits' => 'cmd_commits',
	'builds' => 'cmd_builds', 'gates' => 'cmd_gates', 'changelog' => 'cmd_changelog', 'support' => 'cmd_support',
	'ports' => 'cmd_ports', 'status' => 'cmd_status', 'report' => 'cmd_report', 'handoff' => 'cmd_handoff', 'detect' => 'cmd_detect', 'conflicts' => 'cmd_conflicts',
];
$cmd = $pos[0] ?? '';
if ( ! isset( $map[ $cmd ] ) ) {
	fwrite( STDERR, "yr: unknown command '$cmd'. Run: yr help\n" );
	exit( EXIT_USAGE );
}
try {
	exit( (int) ( __NAMESPACE__ . '\\' . $map[ $cmd ] )( $pos ) );
} catch ( Fail $f ) {
	fwrite( STDERR, 'yr: ' . $f->getMessage() . "\n" );
	exit( $f->getCode() ?: EXIT_FAIL );
}
