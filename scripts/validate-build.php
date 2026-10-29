<?php
/**
 * Release package validator.
 *
 * Usage:
 *   php scripts/validate-build.php <zip-path> <pro|lite> [--require-boot]
 *
 * Environment:
 *   YAYLOYALTY_WP_CLI       Command prefix for the headless boot check, e.g.
 *                           "wp" or "wp --path=/srv/site". Unset, the boot
 *                           check is skipped, and the report says so.
 *   YAYLOYALTY_WP_CLI_ARGS  Extra global args appended to that command, e.g.
 *                           "--skip-plugins=yayloyalty,othersite-plugin".
 *                           The installed copy of this plugin must be skipped
 *                           or its classes collide with the extracted one.
 *
 * A stripping build fails silently: the zip installs and looks correct while
 * carrying code it should have dropped. Runs against the ZIP, never the
 * working tree. PHP 7.4, single file: a validator that needs a loader can
 * fail to load.
 */

declare(strict_types=1);

// Written by registerPro(); keep in sync with apps/admin/src/pro/index.ts.
const PRO_BUNDLE_SENTINEL = 'yayloyalty-pro-bundle-a7f3c1';

// From components/pro/UpgradeToPro.tsx, which Lite keeps (src/pro/ is what is dropped).
const UPSELL_MARKER = 'Upgrade to PRO to unlock this feature now';

const PLUGIN_SLUG = 'yayloyalty';

// The one file allowed to name the Pro namespace in a Lite build.
const PRO_REFERENCE_FILE = 'includes/YayLoyalty.php';

// The guarded class_exists plus the call it guards.
const PRO_REFERENCE_LIMIT = 2;

// Result collection

$results = [];

/**
 * @param bool|null $ok true = PASS, false = FAIL, null = SKIP.
 */
function record(string $name, $ok, string $detail = ''): void {
    global $results;
    $results[] = [
        'name'   => $name,
        'status' => null === $ok ? 'SKIP' : ( $ok ? 'PASS' : 'FAIL' ),
        'detail' => $detail,
    ];
}

function fail_out(string $message): void {
    fwrite( STDERR, "validate-build: $message\n" );
    exit( 2 );
}

/** Recursively list files under $dir as paths relative to it. */
function list_files(string $dir): array {
    $found    = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
    );
    foreach ( $iterator as $file ) {
        if ( $file->isFile() ) {
            $found[] = substr( $file->getPathname(), strlen( $dir ) + 1 );
        }
    }
    sort( $found );
    return $found;
}

function rmdir_recursive(string $dir): void {
    if ( ! is_dir( $dir ) ) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ( $iterator as $item ) {
        $item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
    }
    rmdir( $dir );
}

// Arguments

$argv_rest    = array_slice( $argv, 1 );
$require_boot = false;
$positional   = [];
foreach ( $argv_rest as $arg ) {
    if ( '--require-boot' === $arg ) {
        $require_boot = true;
        continue;
    }
    $positional[] = $arg;
}

if ( count( $positional ) < 2 ) {
    fail_out( 'usage: validate-build.php <zip-path> <pro|lite> [--require-boot]' );
}

list( $zip_path, $build_type ) = $positional;

if ( ! in_array( $build_type, [ 'pro', 'lite' ], true ) ) {
    fail_out( "unknown build type '$build_type' (expected 'pro' or 'lite')" );
}
if ( ! is_file( $zip_path ) ) {
    fail_out( "zip not found: $zip_path" );
}

$is_lite = 'lite' === $build_type;

// Extract

$work = sys_get_temp_dir() . '/yayloyalty-validate-' . getmypid();
rmdir_recursive( $work );
mkdir( $work, 0777, true );

exec( 'unzip -q ' . escapeshellarg( $zip_path ) . ' -d ' . escapeshellarg( $work ), $unzip_out, $unzip_code );
if ( 0 !== $unzip_code ) {
    rmdir_recursive( $work );
    fail_out( "unzip failed with code $unzip_code" );
}

$root = $work . '/' . PLUGIN_SLUG;
record(
    'Zip contains ' . PLUGIN_SLUG . '/ at its root',
    is_dir( $root ),
    is_dir( $root ) ? '' : 'both builds must unpack to the same plugin folder so Lite upgrades to Pro in place'
);
if ( ! is_dir( $root ) ) {
    render_and_exit( $work, $require_boot );
}

$files = list_files( $root );

// Manifest

$manifest_rel  = 'release-manifest.json';
$manifest_path = $root . '/' . $manifest_rel;
$manifest      = null;

if ( is_file( $manifest_path ) ) {
    $manifest = json_decode( (string) file_get_contents( $manifest_path ), true );
}

record( 'release-manifest.json present and parseable', is_array( $manifest ) && isset( $manifest['files'] ) );

if ( is_array( $manifest ) && isset( $manifest['files'] ) ) {
    record(
        'Manifest buildType matches the requested build',
        ( $manifest['buildType'] ?? null ) === $build_type,
        'manifest says ' . var_export( $manifest['buildType'] ?? null, true ) . ", asked for '$build_type'"
    );

    // The version must agree in three places.
    $main_php     = (string) @file_get_contents( $root . '/' . PLUGIN_SLUG . '.php' );
    $const_ok     = preg_match( "/define\(\s*'YAYLOYALTY_VERSION',\s*'([^']+)'/", $main_php, $m_const );
    $header_ok    = preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $main_php, $m_header );
    $manifest_ver = (string) ( $manifest['version'] ?? '' );
    $const_ver    = $const_ok ? $m_const[1] : '';
    $header_ver   = $header_ok ? $m_header[1] : '';

    record(
        'Version agrees across manifest, plugin header and constant',
        '' !== $manifest_ver && $manifest_ver === $const_ver && $manifest_ver === $header_ver,
        "manifest=$manifest_ver header=$header_ver constant=$const_ver"
    );

    // Unlisted files are what catch a stray file surviving a strip.
    $mismatched = [];
    foreach ( $manifest['files'] as $rel => $expected_hash ) {
        $abs = $root . '/' . $rel;
        if ( ! is_file( $abs ) ) {
            $mismatched[] = "$rel (missing)";
            continue;
        }
        if ( hash_file( 'sha256', $abs ) !== $expected_hash ) {
            $mismatched[] = "$rel (hash)";
        }
    }
    record(
        'Every manifested file present with a matching SHA256',
        [] === $mismatched,
        implode( ', ', array_slice( $mismatched, 0, 5 ) )
    );

    $unlisted = array_diff( $files, array_keys( $manifest['files'] ), [ $manifest_rel ] );
    record(
        'No packaged file is missing from the manifest',
        [] === $unlisted,
        implode( ', ', array_slice( $unlisted, 0, 5 ) )
    );
}

// The strip itself

$pro_dir_present = is_dir( $root . '/includes/Pro' );

if ( $is_lite ) {
    record( 'Lite: includes/Pro/ stripped', ! $pro_dir_present );
} else {
    record(
        'Pro: includes/Pro/YayLoyaltyPro.php shipped',
        is_file( $root . '/includes/Pro/YayLoyaltyPro.php' )
    );
}

// Bundle

$bundle_rel  = 'assets/dist/admin/js/main.js';
$bundle_path = $root . '/' . $bundle_rel;
$bundle      = is_file( $bundle_path ) ? (string) file_get_contents( $bundle_path ) : null;

record( "Admin bundle present at $bundle_rel", null !== $bundle );

if ( null !== $bundle ) {
    $sentinel_count = substr_count( $bundle, PRO_BUNDLE_SENTINEL );

    if ( $is_lite ) {
        record(
            'Lite: Pro bundle sentinel absent (tree-shaking worked)',
            0 === $sentinel_count,
            0 === $sentinel_count ? '' : "found $sentinel_count occurrence(s) — a deep import from @/pro/ defeats the IS_PRO_BUILD gate"
        );
    } else {
        // Without this the Lite assertion would pass forever if the sentinel were dropped from both builds.
        record(
            'Pro: Pro bundle sentinel present (Lite check is not vacuous)',
            $sentinel_count > 0,
            $sentinel_count > 0 ? '' : 'sentinel missing from the Pro bundle too, so the Lite assertion proves nothing'
        );
    }

    // Lite only: in Pro the upsell branch is correctly shaken out.
    if ( $is_lite ) {
        record(
            'Lite: upsell surface kept in the bundle (components/pro/)',
            false !== strpos( $bundle, UPSELL_MARKER ),
            'Lite must render upgrade prompts; without them the tier has no upgrade path in the UI at all'
        );
    }

    // A side chunk would carry Pro code past a grep that only reads main.js.
    $js_files = glob( $root . '/assets/dist/admin/js/*.js' ) ?: [];
    record(
        'Bundle is a single entry chunk',
        1 === count( $js_files ),
        implode( ', ', array_map( 'basename', $js_files ) )
    );
}

// No runtime Pro flag reaches the browser

$php_files = array_values(
    array_filter(
        $files,
        static function ( string $rel ): bool {
            return '.php' === strtolower( substr( $rel, -4 ) );
        }
    )
);

$flag_hits = [];
foreach ( $php_files as $rel ) {
    $src = (string) file_get_contents( $root . '/' . $rel );
    if ( preg_match( "/['\"](isPro|isPremium|is_pro|isProBuild)['\"]\s*=>/", $src ) ) {
        $flag_hits[] = $rel;
    }
}
record(
    'No Pro flag localized to the SPA',
    [] === $flag_hits,
    implode( ', ', $flag_hits ) . ' — the SPA is gated at build time; a runtime flag would reintroduce the split it replaced'
);

// Syntax

$lint_failures = [];
foreach ( $php_files as $rel ) {
    exec(
        escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $root . '/' . $rel ) . ' 2>&1',
        $lint_out,
        $lint_code
    );
    if ( 0 !== $lint_code ) {
        $lint_failures[] = $rel;
    }
    $lint_out = [];
}
record(
    'php -l clean across ' . count( $php_files ) . ' PHP files',
    [] === $lint_failures,
    implode( ', ', array_slice( $lint_failures, 0, 5 ) )
);

// Lite still names Pro, but only once and only in one file

if ( $is_lite ) {
    $refs = [];
    foreach ( $php_files as $rel ) {
        // The scoped admin shell has its own "Pro" vocabulary this plugin does not strip.
        if ( 0 === strpos( $rel, 'vendor-prefixed/' ) || 0 === strpos( $rel, 'vendor/' ) ) {
            continue;
        }
        $src   = (string) file_get_contents( $root . '/' . $rel );
        $code  = strip_php_comments( $src );
        $count = preg_match_all( '/\bPro\b/', $code );
        if ( $count > 0 ) {
            $refs[ $rel ] = $count;
        }
    }

    $confined = array_keys( $refs ) === [ PRO_REFERENCE_FILE ]
        && $refs[ PRO_REFERENCE_FILE ] <= PRO_REFERENCE_LIMIT;
    $confined = $confined || [] === $refs;

    $detail = [];
    foreach ( $refs as $rel => $count ) {
        $detail[] = "$rel x$count";
    }

    // Not zero: the guarded class_exists must survive so Lite can detect a Pro drop-in.
    record(
        'Lite: Pro named only by the guarded seam in ' . PRO_REFERENCE_FILE,
        $confined,
        implode( ', ', $detail )
    );
}

// The admin-shell strip and text-domain rewrite (build_package in scripts/run.sh)

if ( $is_lite ) {
    // Mirrors LITE_SHELL_STRIP in scripts/run.sh.
    $shell_strip = [
        'src/License/', 'src/Registry/', 'src/Contracts/AddonHostAdapter.php', 'src/Menu/ExternalPluginMenuAdapter.php',
        'src/Pages/LicensesPage.php', 'src/Pages/RecommendedPluginsPage.php', 'views/license-card.php',
        'views/license-activate-card.php', 'views/licenses-page.php', 'views/recommended-plugins-data.php',
        'views/recommended-plugins-page.php', 'assets/css/licenses.css', 'assets/css/other-plugins.css',
        'assets/js/license.js', 'assets/js/other-plugins.js',
    ];
    $shell_leftovers = [];
    foreach ( $files as $rel ) {
        foreach ( $shell_strip as $path ) {
            if ( 0 === strpos( $rel, 'vendor-prefixed/' . $path ) ) {
                $shell_leftovers[] = $rel;
            }
        }
    }
    record(
        'Lite: admin-shell licensing and Recommended Plugins stripped',
        [] === $shell_leftovers,
        implode( ', ', array_slice( $shell_leftovers, 0, 5 ) )
    );

    // A stripped class still named in the shell or in Composer's maps fatals when reached, and
    // WordPress.org review reads a listed EDD_SL_Plugin_Updater as an updater.
    $shell_refs = [];
    foreach ( $php_files as $rel ) {
        if ( 0 !== strpos( $rel, 'vendor-prefixed/' ) && 0 !== strpos( $rel, 'vendor/composer/' ) ) {
            continue;
        }
        $src = (string) file_get_contents( $root . '/' . $rel );
        if ( preg_match( '/AdminShell\\\\{1,2}(License|Registry)\\\\|LicensesPage|RecommendedPluginsPage|ExternalPluginMenuAdapter|EDD_SL_Plugin_Updater/', $src ) ) {
            $shell_refs[] = $rel;
        }
    }
    record(
        'Lite: nothing names a stripped admin-shell class',
        [] === $shell_refs,
        implode( ', ', array_slice( $shell_refs, 0, 5 ) )
    );
}

$foreign_domains = [];
foreach ( $php_files as $rel ) {
    if ( 0 !== strpos( $rel, 'vendor-prefixed/' ) ) {
        continue;
    }
    if ( preg_match( "/'yaycommerce'\s*\)/", (string) file_get_contents( $root . '/' . $rel ) ) ) {
        $foreign_domains[] = $rel;
    }
}
record(
    'Admin-shell gettext calls use the ' . PLUGIN_SLUG . ' text domain',
    [] === $foreign_domains,
    implode( ', ', array_slice( $foreign_domains, 0, 5 ) )
);

// Inline Pro-field gates, read from the source, not the zip

// Lite ships these field keys, because a downgraded site keeps its Pro data, so only the
// IS_PRO_BUILD guard in the source tells "gated" from "ungated". __DIR__ is scripts/, so
// this reads the repo whatever the package holds.
check_inline_pro_field_gates();

// Dev-only artifacts

$dev_leftovers = array_values(
    array_filter(
        $files,
        static function ( string $rel ): bool {
            return 'includes/Engine/Register/RegisterDev.php' === $rel
                || '.map' === substr( $rel, -4 )
                || false !== strpos( $rel, '/.vite/' )
                || '.DS_Store' === basename( $rel );
        }
    )
);
record(
    'No dev-only artifacts packaged',
    [] === $dev_leftovers,
    implode( ', ', array_slice( $dev_leftovers, 0, 5 ) )
);

// The zip has no RegisterDev.php, so a `true` default leaves the admin page blank.
record(
    'YAYLOYALTY_IS_DEVELOPMENT defaults to false',
    1 === preg_match(
        "/define\(\s*'YAYLOYALTY_IS_DEVELOPMENT',\s*false\s*\)/",
        (string) @file_get_contents( $root . '/' . PLUGIN_SLUG . '.php' )
    )
);

// Headless boot

run_boot_check( $root, $build_type );

// Report

render_and_exit( $work, $require_boot );

// Functions

/**
 * Design's inline Pro-field gate (DesignVisibilitySection) is not covered
 * by anything else: the bundle sentinel only proves the whole Pro *sections*
 * were shaken out. Fails when a Pro-only field name appears in a file without
 * IS_PRO_BUILD also appearing there, which is the one thing that can drop
 * silently in a later edit.
 */
function check_inline_pro_field_gates(): void {
    $pro_only_fields = [ 'custom_pages' ];
    $files           = [
        __DIR__ . '/../apps/admin/src/pages/settings/DesignVisibilitySection.tsx',
    ];

    $ungated = [];
    foreach ( $files as $path ) {
        if ( ! is_file( $path ) ) {
            continue;
        }
        $src = (string) file_get_contents( $path );
        foreach ( $pro_only_fields as $field ) {
            if ( false !== strpos( $src, $field ) && false === strpos( $src, 'IS_PRO_BUILD' ) ) {
                $ungated[] = basename( $path );
                break;
            }
        }
    }

    record(
        'Design: inline Pro fields stay guarded with IS_PRO_BUILD',
        [] === $ungated,
        implode( ', ', $ungated ) . ' — a Pro-only field name appears without an IS_PRO_BUILD guard in the same file'
    );
}

/** Strips comments so a reference count reflects code, not prose about the seams. */
function strip_php_comments(string $src): string {
    $out = '';
    foreach ( token_get_all( $src ) as $token ) {
        if ( is_array( $token ) ) {
            if ( T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
                continue;
            }
            $out .= $token[1];
            continue;
        }
        $out .= $token;
    }
    return $out;
}

/**
 * Boots the extracted plugin inside a real WordPress: catches a stripped
 * class something still instantiates. Skips loudly without WP-CLI.
 */
function run_boot_check(string $root, string $build_type): void {
    $wp_cli = getenv( 'YAYLOYALTY_WP_CLI' );
    if ( false === $wp_cli || '' === trim( (string) $wp_cli ) ) {
        record(
            'Headless boot with no fatal',
            null,
            'YAYLOYALTY_WP_CLI is not set; it is documented at the top of scripts/validate-build.php. Pass --require-boot to treat this as a failure.'
        );
        return;
    }

    $script = tempnam( sys_get_temp_dir(), 'yayboot' ) . '.php';
    file_put_contents( $script, boot_script_source() );

    $cmd = trim( (string) $wp_cli )
        . ' eval-file ' . escapeshellarg( $script )
        . ' ' . escapeshellarg( $root )
        . ' ' . escapeshellarg( $build_type )
        . ' ' . trim( (string) getenv( 'YAYLOYALTY_WP_CLI_ARGS' ) )
        . ' 2>&1';

    exec( $cmd, $out, $code );
    unlink( $script );

    $payload = null;
    foreach ( $out as $line ) {
        if ( 0 === strpos( $line, 'YAYLOYALTY_BOOT_RESULT:' ) ) {
            $payload = json_decode( substr( $line, strlen( 'YAYLOYALTY_BOOT_RESULT:' ) ), true );
        }
    }

    if ( 0 !== $code || ! is_array( $payload ) ) {
        record(
            'Headless boot with no fatal',
            false,
            'exit ' . $code . ': ' . implode( ' | ', array_slice( $out, -3 ) )
        );
        return;
    }

    record( 'Headless boot with no fatal', true, 'WooCommerce ' . ( $payload['wc'] ? 'present' : 'absent' ) );

    record(
        'Booted plugin reports is_pro() === ' . ( 'pro' === $build_type ? 'true' : 'false' ),
        $payload['isPro'] === ( 'pro' === $build_type ),
        'is_pro() returned ' . var_export( $payload['isPro'], true )
    );

    record(
        'Localized payload carries no Pro-shaped key',
        [] === $payload['proKeys'],
        implode( ', ', $payload['proKeys'] )
    );
}

/** The script handed to `wp eval-file`; a string so the validator stays one file. */
function boot_script_source(): string {
    return <<<'BOOT'
<?php
/**
 * Boots an extracted YayLoyalty package inside a live WordPress.
 *
 * Run via: wp eval-file <this> <plugin-dir> <pro|lite> --skip-plugins=yayloyalty
 * The installed copy MUST be skipped or its class declarations collide.
 */

list( $dir, $expected ) = $args;

require_once rtrim( $dir, '/' ) . '/yayloyalty.php';

// plugin_init() returns early without WooCommerce; initialize() exercises the singletons either way.
\YayLoyalty\plugin_init();
\YayLoyalty\YayLoyalty::initialize();

$pro_keys = [];
$scan     = static function ( $value, string $path ) use ( &$scan, &$pro_keys ): void {
    if ( ! is_array( $value ) ) {
        return;
    }
    foreach ( $value as $key => $child ) {
        $here = '' === $path ? (string) $key : $path . '.' . $key;
        // Whole words of a snake_case or camelCase key, so "prompt" and "product" don't match.
        $words = is_string( $key )
            ? preg_split( '/[^a-z0-9]+/', strtolower( preg_replace( '/([a-z0-9])([A-Z])/', '$1_$2', $key ) ) )
            : [];
        if ( array_intersect( [ 'pro', 'premium' ], $words ) ) {
            $pro_keys[] = $here;
        }
        $scan( $child, $here );
    }
};

$scan( \YayLoyalty\Helpers\LocalizeHelper::get_meta(), '' );
$scan( \YayLoyalty\Helpers\LocalizeHelper::get_admin_data(), '' );

echo 'YAYLOYALTY_BOOT_RESULT:' . wp_json_encode(
    [
        'isPro'    => \YayLoyalty\YayLoyalty::is_pro(),
        'wc'       => function_exists( 'WC' ),
        'proKeys'  => $pro_keys,
        'expected' => $expected,
    ]
) . "\n";
BOOT;
}

/** Prints the results table and exits with the right code. */
function render_and_exit(string $work, bool $require_boot): void {
    global $results;

    rmdir_recursive( $work );

    $width = 0;
    foreach ( $results as $row ) {
        $width = max( $width, strlen( $row['name'] ) );
    }

    echo "\n";
    $failed  = 0;
    $skipped = 0;
    foreach ( $results as $row ) {
        $mark = [
            'PASS' => "\033[0;32mPASS\033[0m",
            'FAIL' => "\033[0;31mFAIL\033[0m",
            'SKIP' => "\033[1;33mSKIP\033[0m",
        ][ $row['status'] ];

        printf( "  %s  %-{$width}s\n", $mark, $row['name'] );

        if ( 'PASS' !== $row['status'] && '' !== trim( $row['detail'], ' —' ) ) {
            printf( "        %s\n", $row['detail'] );
        }

        if ( 'FAIL' === $row['status'] ) {
            ++$failed;
        }
        if ( 'SKIP' === $row['status'] ) {
            ++$skipped;
        }
    }

    $total = count( $results );
    echo "\n";

    if ( $failed > 0 ) {
        printf( "  %d of %d checks FAILED.\n\n", $failed, $total );
        exit( 1 );
    }

    if ( $skipped > 0 && $require_boot ) {
        printf( "  %d check(s) skipped and --require-boot was given.\n\n", $skipped );
        exit( 1 );
    }

    printf( "  %d checks passed%s.\n\n", $total - $skipped, $skipped > 0 ? ", $skipped skipped" : '' );
    exit( 0 );
}
