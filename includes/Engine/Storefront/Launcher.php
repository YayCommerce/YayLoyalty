<?php
namespace YayLoyalty\Engine\Storefront;

use YayLoyalty\Controllers\StorefrontActivityRestController;
use YayLoyalty\Engine\Earning\EarningService;
use YayLoyalty\Engine\Earning\ProgramGate;
use YayLoyalty\Engine\Points\ContextFactory;
use YayLoyalty\Engine\Register\ScriptName;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Helpers\DesignHelper;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * The storefront launcher: decides once per request, then enqueues and prints.
 * Read-only for now: balance, ways to earn and recent activity. Redeeming is not built yet.
 */
final class Launcher {

    /** The built stylesheet, relative to the plugin root. */
    private const STYLE_PATH = 'assets/dist/storefront/launcher.css';

    /** @var array|null The templates' view, or null when nothing renders on this request. */
    private $view;

    public static function register(): void {
        $launcher = new self();

        // `wp`: after the main query, so conditional tags and the queried page are known.
        add_action( 'wp', [ $launcher, 'prepare' ] );
        add_action( 'wp_enqueue_scripts', [ $launcher, 'enqueue' ] );
        add_action( 'wp_footer', [ $launcher, 'render' ] );
    }

    public function prepare(): void {
        try {
            $design     = DesignHelper::get_design();
            $this->view = $this->should_render( $design ) ? $this->build_view( $design ) : null;
        } catch ( \Throwable $e ) {
            // The storefront outranks the launcher: a failure here hides it, never the page.
            Log::warning( 'Launcher', 'not rendered: ' . $e->getMessage() );
            $this->view = null;
        }
    }

    public function enqueue(): void {
        if ( null === $this->view ) {
            return;
        }

        wp_enqueue_style( ScriptName::STOREFRONT_STYLE, YAYLOYALTY_PLUGIN_URL . self::STYLE_PATH, [], self::asset_version( self::STYLE_PATH ) );
        wp_enqueue_script(
            ScriptName::STOREFRONT_SCRIPT,
            YAYLOYALTY_PLUGIN_URL . 'assets/storefront/launcher.js',
            [],
            self::asset_version( 'assets/storefront/launcher.js' ),
            [
                'in_footer' => true,
                'strategy'  => 'defer',
            ]
        );
    }

    /**
     * The plugin version plus the file's mtime: the template and its stylesheet/script change together,
     * so a rebuild must reach the browser and page caches without a version bump.
     */
    public static function asset_version( string $relative_path ): string {
        $mtime = @filemtime( YAYLOYALTY_PLUGIN_DIR . $relative_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing build falls back to the version.

        return false === $mtime ? YAYLOYALTY_VERSION : YAYLOYALTY_VERSION . '.' . $mtime;
    }

    public function render(): void {
        if ( null === $this->view ) {
            return;
        }

        echo self::markup( $this->view ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the templates escape every value.
    }

    /**
     * The launcher's HTML for a view: the storefront prints it, the Design tab's preview frames it.
     *
     * @param array $view LauncherPayload output plus `style`; optional `is_open` and `activity` (rows to print instead of skeletons).
     */
    public static function markup( array $view ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- the included template reads $view.
        ob_start();
        include __DIR__ . '/templates/launcher.php';

        return (string) ob_get_clean();
    }

    /** The stylesheet's URL, versioned like the enqueue, for a page that links it itself (the Design tab's preview). */
    public static function style_url(): string {
        return add_query_arg( 'ver', self::asset_version( self::STYLE_PATH ), YAYLOYALTY_PLUGIN_URL . self::STYLE_PATH );
    }

    private function should_render( array $design ): bool {
        if ( is_embed() || is_feed() || ! function_exists( 'wc_get_page_permalink' ) ) {
            return false;
        }

        $user_id = get_current_user_id();
        $roles   = $user_id > 0 ? (array) wp_get_current_user()->roles : [];

        $is_visible = LauncherVisibility::is_visible(
            $design['visibility'],
            is_shop() || is_product() || is_product_taxonomy(),
            (int) get_queried_object_id(),
            $roles
        );

        if ( ! $is_visible ) {
            return false;
        }

        return self::viewer_can_earn( $user_id );
    }

    /**
     * Whether a viewer is shown anything about earning. Same gates as earning, Pro's included.
     *
     * @param int $user_id The viewer; 0 for a guest.
     */
    public static function viewer_can_earn( int $user_id ): bool {
        // A guest always reads as "no customer" while the program is on, and that is who the pitch is for.
        if ( $user_id < 1 ) {
            return ProgramGate::REASON_NO_CUSTOMER === EarningService::gate_reason( CampaignTrigger::PURCHASE, ContextFactory::for_advert( Dates::now() ) );
        }

        return null === EarningService::gate_reason( CampaignTrigger::PURCHASE, ContextFactory::from_user( $user_id, Dates::now() ) );
    }

    private function build_view( array $design ): array {
        return self::view( $design, LauncherPayload::for_user( $design, get_current_user_id() ), true );
    }

    /**
     * The templates' view from a payload: the Design tab's theme variables and, for a live member, the
     * activity route launcher.js fetches. The Design tab's preview passes $is_live false and prints rows instead.
     */
    public static function view( array $design, array $payload, bool $is_live ): array {
        $payload['style'] = self::theme_vars( $design['launcher'] );

        if ( $is_live && null !== $payload['member'] ) {
            $payload['activity_url'] = rest_url( StorefrontActivityRestController::REST_NAMESPACE . StorefrontActivityRestController::ROUTE );
            $payload['nonce']        = wp_create_nonce( 'wp_rest' );
        }

        return $payload;
    }

    /**
     * The kit's tokens re-pointed at the Design tab: brand colour → --primary (and its hover/ring),
     * corner radius → --radius. Foreground flips to dark on a light brand colour so the label stays readable.
     */
    private static function theme_vars( array $launcher ): string {
        // get_design() has already coerced this to a valid hex (DesignCoercion), so it is safe in a style attribute.
        $color = (string) $launcher['brand_color'];

        return sprintf(
            '--primary:%1$s;--primary-accent:color-mix(in srgb,%1$s 85%%,#000);--ring:%1$s;--primary-foreground:%2$s;--radius:%3$dpx;',
            $color,
            self::is_light( $color ) ? '#171719' : '#ffffff',
            (int) $launcher['corner_radius']
        );
    }

    /** Relative luminance above the point where white text drops below 4.5:1. */
    private static function is_light( string $hex ): bool {
        $hex = ltrim( $hex, '#' );

        if ( 3 === strlen( $hex ) ) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $channel = static function ( string $pair ): float {
            $value = hexdec( $pair ) / 255;

            return $value <= 0.03928 ? $value / 12.92 : ( ( $value + 0.055 ) / 1.055 ) ** 2.4;
        };

        $luminance = 0.2126 * $channel( substr( $hex, 0, 2 ) ) + 0.7152 * $channel( substr( $hex, 2, 2 ) ) + 0.0722 * $channel( substr( $hex, 4, 2 ) );

        return $luminance > 0.179;
    }
}
