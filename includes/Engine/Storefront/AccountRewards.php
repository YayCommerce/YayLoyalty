<?php
namespace YayLoyalty\Engine\Storefront;

use YayLoyalty\Controllers\Schemas\LedgerQueryArgs;
use YayLoyalty\Engine\Earning\ProgramGate;
use YayLoyalty\Engine\Register\ScriptName;
use YayLoyalty\Helpers\DesignHelper;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Repositories\LedgerRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The My Account "Rewards" tab: the shopper's balance, the programme's rules and their points history.
 */
final class AccountRewards {

    public const ENDPOINT = 'rewards';

    /** Bump when the endpoint's rewrite rules change, so every site flushes once. */
    public const REWRITE_VERSION = '1';

    public const OPTION_REWRITE_VERSION = 'yayloyalty_rewrite_version';

    public const PER_PAGE = 20;

    public const FILTER_QUERY_ARG = 'points_type';

    /** The one file enqueued: it holds the tokens and @imports each section's file beside it. */
    private const STYLE_PATH = 'assets/storefront/account/rewards-tokens.css';

    /** Hooked on every request: a permalink save in wp-admin rebuilds the rules and must still see the endpoint. */
    public static function register(): void {
        add_filter( 'woocommerce_get_query_vars', [ self::class, 'add_query_var' ] );
        add_action( 'init', [ self::class, 'maybe_flush_rewrite_rules' ], 99 );
        add_filter( 'woocommerce_account_menu_items', [ self::class, 'add_menu_item' ] );
        add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', [ self::class, 'title' ] );
        add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', [ self::class, 'render' ] );
        add_action( 'init', [ self::class, 'register_style' ] );
        add_action( 'wp_enqueue_scripts', [ self::class, 'enqueue' ] );
        add_action( 'template_redirect', [ AccountHistoryCsv::class, 'maybe_send' ] );
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

    /** The tab's stylesheet, which the placements, their blocks and the launcher panel share. */
    public static function register_style(): void {
        wp_register_style( ScriptName::ACCOUNT_STYLE, YAYLOYALTY_PLUGIN_URL . self::STYLE_PATH, [], Launcher::asset_version( self::STYLE_PATH ) );
    }

    /** The stylesheet's URL, versioned like the enqueue, for the Design tab's preview, which links it itself. */
    public static function style_url(): string {
        return add_query_arg( 'ver', Launcher::asset_version( self::STYLE_PATH ), YAYLOYALTY_PLUGIN_URL . self::STYLE_PATH );
    }

    public static function enqueue(): void {
        if ( is_wc_endpoint_url( self::ENDPOINT ) ) {
            wp_enqueue_style( ScriptName::ACCOUNT_STYLE );
        }
    }

    public static function title(): string {
        return __( 'Rewards', 'yayloyalty' );
    }

    /** The tab's URL: the page as the endpoint value, the history filter as `points_type`. */
    public static function url( int $page = 1, string $filter = 'all' ): string {
        $url = wc_get_endpoint_url( self::ENDPOINT, $page > 1 ? (string) $page : '', wc_get_page_permalink( 'myaccount' ) );

        return 'all' === $filter ? $url : add_query_arg( self::FILTER_QUERY_ARG, $filter, $url );
    }

    /**
     * The history filters and their labels. LedgerQueryArgs::filter_types() owns the groups, so one it drops
     * drops here too. Adjustments show under All only.
     *
     * @return array<string, string>
     */
    public static function filter_labels(): array {
        $labels = [
            'all'    => __( 'All', 'yayloyalty' ),
            'earn'   => __( 'Earned', 'yayloyalty' ),
            'redeem' => __( 'Spent', 'yayloyalty' ),
            'expire' => __( 'Expired', 'yayloyalty' ),
        ];

        return array_intersect_key( $labels, array_flip( LedgerQueryArgs::filter_types() ) );
    }

    /**
     * The history's column headings, for the table and the CSV.
     *
     * @return array{date: string, description: string, points: string, balance: string}
     */
    public static function column_labels(): array {
        return [
            'date'        => __( 'Date', 'yayloyalty' ),
            'description' => __( 'Description', 'yayloyalty' ),
            // 0 picks the plural label.
            'points'      => SettingsHelper::points_label( 0 ),
            'balance'     => __( 'Balance', 'yayloyalty' ),
        ];
    }

    /** @param string $value The endpoint value: the history page number. */
    public static function render( $value = '' ): void {
        if ( ! ProgramGate::program_enabled() ) {
            self::print_state( __( 'Rewards are currently unavailable', 'yayloyalty' ), __( 'The rewards program is currently paused. Please check back later.', 'yayloyalty' ) );

            return;
        }

        // Opted out or an excluded role: this shopper neither earns nor redeems.
        if ( ! Launcher::viewer_can_earn( get_current_user_id() ) ) {
            self::print_state( __( 'Rewards aren’t available for this account', 'yayloyalty' ), __( 'This account isn’t eligible for the rewards program. Contact the store if you need help.', 'yayloyalty' ) );

            return;
        }

        $html = OutputCapture::capture(
            static function () use ( $value ): void {
                $view = self::view( get_current_user_id(), max( 1, absint( $value ) ) );

                include __DIR__ . '/templates/account-rewards.php';
            },
            'AccountRewards'
        );

        if ( null === $html ) {
            self::print_unavailable();

            return;
        }

        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the template escapes every value.
    }

    /**
     * The points card's data: the payload for this viewer and when their points expire.
     *
     * @param int $user_id The viewer; 0 for a guest.
     * @return array{payload: array, expires_at: ?string} `expires_at` the payload member's: in UTC, and only
     *         within LauncherPayload::EXPIRING_SOON_DAYS; null otherwise, which hides the notice.
     */
    public static function card_view( int $user_id ): array {
        $payload = LauncherPayload::for_user( DesignHelper::get_design(), $user_id );

        return [
            'payload'    => $payload,
            'expires_at' => null === $payload['member'] ? null : $payload['member']['expires_at'],
        ];
    }

    /**
     * @return array{payload: array, expires_at: ?string, earn: array, history: array{rows: array, total: int, page: int, filter: string}}
     */
    private static function view( int $user_id, int $page ): array {
        $filter  = self::current_filter();
        $history = ( new LedgerRepository() )->paginate_for_user(
            $user_id,
            [
                'types'    => LedgerQueryArgs::types_of( $filter ),
                'page'     => $page,
                'per_page' => self::PER_PAGE,
            ]
        );

        $card = self::card_view( $user_id );

        return $card + [
            'earn'    => LauncherEarnRules::for_viewer( $card['payload'], $user_id ),
            'history' => [
                'rows'   => ShopperActivityRow::history_rows( $history['items'] ),
                'total'  => (int) $history['total'],
                'page'   => $page,
                'filter' => $filter,
            ],
        ];
    }

    /** The requested history filter; an unknown one falls back to all. */
    private static function current_filter(): string {
        $filter = isset( $_GET[ self::FILTER_QUERY_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::FILTER_QUERY_ARG ] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only view filter.

        return array_key_exists( $filter, self::filter_labels() ) ? $filter : 'all';
    }

    private static function print_unavailable(): void {
        wc_print_notice( __( 'Rewards are not available right now.', 'yayloyalty' ), 'notice' );
    }

    /** The tab's whole body when the shopper cannot take part: a heading and one line in a card. */
    private static function print_state( string $title, string $text ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- the template reads both.
        include __DIR__ . '/templates/account-rewards-state.php';
    }

    /** The tab's intro line, on every state. */
    public static function intro(): string {
        return __( 'Your points, perks, and activity in one place.', 'yayloyalty' );
    }
}
