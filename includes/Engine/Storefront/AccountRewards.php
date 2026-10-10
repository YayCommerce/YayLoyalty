<?php
namespace YayLoyalty\Engine\Storefront;

use YayLoyalty\Engine\Earning\ProgramGate;
use YayLoyalty\Engine\Points\ContextFactory;
use YayLoyalty\Engine\Register\ScriptName;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Helpers\DesignHelper;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * The My Account "Rewards" tab: the shopper's balance, the programme's rules and their points history.
 */
final class AccountRewards {

    public const ENDPOINT = 'rewards';

    /** Bump when the endpoint's rewrite rules change, so every site flushes once. */
    public const REWRITE_VERSION = '1';

    public const OPTION_REWRITE_VERSION = 'yayloyalty_rewrite_version';

    private const STYLE_PATH = 'assets/storefront/account.css';

    /** Hooked on every request: a permalink save in wp-admin rebuilds the rules and must still see the endpoint. */
    public static function register(): void {
        add_filter( 'woocommerce_get_query_vars', [ self::class, 'add_query_var' ] );
        add_action( 'init', [ self::class, 'maybe_flush_rewrite_rules' ], 99 );
        add_filter( 'woocommerce_account_menu_items', [ self::class, 'add_menu_item' ] );
        add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', [ self::class, 'title' ] );
        add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', [ self::class, 'render' ] );
        add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue' ] );
    }

    /**
     * @param array<string, string> $vars
     * @return array<string, string>
     */
    public static function add_query_var( array $vars ): array {
        $vars[ self::ENDPOINT ] = self::ENDPOINT;

        return $vars;
    }

    public static function maybe_flush_rewrite_rules(): void {
        if ( get_option( self::OPTION_REWRITE_VERSION ) === self::REWRITE_VERSION ) {
            return;
        }

        flush_rewrite_rules( false );
        update_option( self::OPTION_REWRITE_VERSION, self::REWRITE_VERSION );
    }

    /**
     * Before "Account details", else before "Log out", else last.
     *
     * @param array<string, string> $items
     * @return array<string, string>
     */
    public static function add_menu_item( array $items ): array {
        if ( ! ProgramGate::program_enabled() ) {
            return $items;
        }

        $before = isset( $items['edit-account'] ) ? 'edit-account' : ( isset( $items['customer-logout'] ) ? 'customer-logout' : null );
        $item   = [ self::ENDPOINT => self::title() ];

        if ( null === $before ) {
            return $items + $item;
        }

        $position = (int) array_search( $before, array_keys( $items ), true );

        return array_slice( $items, 0, $position, true ) + $item + array_slice( $items, $position, null, true );
    }

    public static function enqueue(): void {
        if ( ! is_wc_endpoint_url( self::ENDPOINT ) ) {
            return;
        }

        wp_enqueue_style( ScriptName::ACCOUNT_STYLE, YAYLOYALTY_PLUGIN_URL . self::STYLE_PATH, [], Launcher::asset_version( self::STYLE_PATH ) );
    }

    public static function title(): string {
        return __( 'Rewards', 'yayloyalty' );
    }

    /** The tab's URL: the page as the endpoint value, the history filter as `points_type`. */
    public static function url( int $page = 1, string $filter = 'all' ): string {
        $url = wc_get_endpoint_url( self::ENDPOINT, $page > 1 ? (string) $page : '', wc_get_page_permalink( 'myaccount' ) );

        return 'all' === $filter ? $url : add_query_arg( 'points_type', $filter, $url );
    }

    public static function render(): void {
        if ( ! ProgramGate::program_enabled() ) {
            self::print_unavailable();

            return;
        }

        $buffer_level = ob_get_level();

        try {
            $view = self::view( get_current_user_id() );

            ob_start();
            include __DIR__ . '/templates/account-rewards.php';
            echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the template escapes every value.
        } catch ( \Throwable $e ) {
            // Drop the half-printed tab, never the theme's own buffers.
            while ( ob_get_level() > $buffer_level ) {
                ob_end_clean();
            }

            Log::warning( 'AccountRewards', 'not rendered: ' . $e->getMessage() );
            self::print_unavailable();
        }
    }

    /**
     * @return array{payload: array, expires_at: ?string, can_earn: bool}
     */
    private static function view( int $user_id ): array {
        $customers = new CustomerRepository();
        $customer  = $customers->find_by_user_id( $user_id );

        return [
            'payload'    => ( new LauncherPayload( new CampaignRepository(), new RewardRepository(), $customers ) )->build( DesignHelper::get_design(), $user_id ),
            'expires_at' => null === $customer ? null : $customer->get_expires_at(),
            'can_earn'   => null === ( new ProgramGate() )->check( CampaignTrigger::PURCHASE, ContextFactory::from_user( $user_id, Dates::now() ) ),
        ];
    }

    private static function print_unavailable(): void {
        wc_print_notice( __( 'Rewards are not available right now.', 'yayloyalty' ), 'notice' );
    }
}
