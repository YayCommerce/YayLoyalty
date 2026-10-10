<?php
namespace YayLoyalty\Engine\Storefront;

use YayLoyalty\Engine\Earning\ProgramGate;
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

    /** Hooked on every request: a permalink save in wp-admin rebuilds the rules and must still see the endpoint. */
    public static function register(): void {
        add_filter( 'woocommerce_get_query_vars', [ self::class, 'add_query_var' ] );
        add_action( 'init', [ self::class, 'maybe_flush_rewrite_rules' ], 99 );
        add_filter( 'woocommerce_account_menu_items', [ self::class, 'add_menu_item' ] );
        add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', [ self::class, 'title' ] );
        add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', [ self::class, 'render' ] );
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

    public static function title(): string {
        return __( 'Rewards', 'yayloyalty' );
    }

    /** The tab's URL: the page as the endpoint value, the history filter as `points_type`. */
    public static function url( int $page = 1, string $filter = 'all' ): string {
        $url = wc_get_endpoint_url( self::ENDPOINT, $page > 1 ? (string) $page : '', wc_get_page_permalink( 'myaccount' ) );

        return 'all' === $filter ? $url : add_query_arg( 'points_type', $filter, $url );
    }

    /** @param string $value The endpoint value: the history page number. */
    public static function render( $value = '' ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- read by the template from Task 3 on.
        if ( ! ProgramGate::program_enabled() ) {
            self::print_unavailable();

            return;
        }

        try {
            ob_start();
            include __DIR__ . '/templates/account-rewards.php';
            echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the template escapes every value.
        } catch ( \Throwable $e ) {
            ob_end_clean();
            Log::warning( 'AccountRewards', 'not rendered: ' . $e->getMessage() );
            self::print_unavailable();
        }
    }

    private static function print_unavailable(): void {
        wc_print_notice( __( 'Rewards are not available right now.', 'yayloyalty' ), 'notice' );
    }
}
