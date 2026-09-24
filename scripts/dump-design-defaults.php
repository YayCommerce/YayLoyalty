<?php
/**
 * Dump DesignHelper::get_design() output (defaults plus the derived launcher.icon_upload_url
 * and schema_version a fresh, never-saved install would actually return) to JSON for use as
 * a vitest fixture. This is the shape designSchema.ts validates, not the bare
 * DesignSchema::get_default_design() array, which omits both.
 * Run from repo root: php scripts/dump-design-defaults.php > apps/admin/tests/fixtures/design-defaults.json
 */

define( 'ABSPATH', __DIR__ . '/../' );

// Minimal WP shims: no option saved yet, no attachment upload, translations passed through.
if ( ! function_exists( '__' ) ) {
    function __( string $text, string $domain = 'default' ): string {
        return $text;
    }
}

if ( ! function_exists( 'get_option' ) ) {
    function get_option( string $option, $default = false ) {
        return $default;
    }
}

if ( ! function_exists( 'wp_get_attachment_url' ) ) {
    function wp_get_attachment_url( int $attachment_id ): string {
        return '';
    }
}

spl_autoload_register(
    static function ( string $class_name ): void {
        $prefix = 'YayLoyalty\\';

        if ( 0 !== strncmp( $prefix, $class_name, strlen( $prefix ) ) ) {
            return;
        }

        $file = ABSPATH . 'includes/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';

        if ( file_exists( $file ) ) {
            require $file;
        }
    }
);

$design = \YayLoyalty\Helpers\DesignHelper::get_design();
echo json_encode( $design, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
