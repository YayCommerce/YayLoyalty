<?php
namespace YayLoyalty\Engine\Storefront;

use YayLoyalty\Controllers\Schemas\LedgerQueryArgs;
use YayLoyalty\Engine\Earning\EarningService;
use YayLoyalty\Engine\Earning\ProgramGate;
use YayLoyalty\Engine\Points\ContextFactory;
use YayLoyalty\Engine\Register\ScriptName;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Helpers\DesignHelper;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
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

    public const PER_PAGE = 20;

    public const FILTER_QUERY_ARG = 'points_type';

    private const STYLE_PATH = 'assets/storefront/account.css';

    /** Hooked on every request: a permalink save in wp-admin rebuilds the rules and must still see the endpoint. */
    public static function register(): void {
        add_filter( 'woocommerce_get_query_vars', [ self::class, 'add_query_var' ] );
        add_action( 'init', [ self::class, 'maybe_flush_rewrite_rules' ], 99 );
        add_filter( 'woocommerce_account_menu_items', [ self::class, 'add_menu_item' ] );
        add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', [ self::class, 'title' ] );
        add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', [ self::class, 'render' ] );
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
            self::print_unavailable();

            return;
        }

        $buffer_level = ob_get_level();

        try {
            $view = self::view( get_current_user_id(), max( 1, absint( $value ) ) );

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
     * @return array{payload: array, expires_at: ?string, can_earn: bool, history: array{rows: array, total: int, page: int, filter: string}}
     */
    private static function view( int $user_id, int $page ): array {
        $customers = new CustomerRepository();
        $customer  = $customers->find_by_user_id( $user_id );
        $filter    = self::current_filter();
        $history   = ( new LedgerRepository() )->paginate_for_user(
            $user_id,
            [
                'types'    => LedgerQueryArgs::types_of( $filter ),
                'page'     => $page,
                'per_page' => self::PER_PAGE,
            ]
        );

        return [
            'payload'    => ( new LauncherPayload( new CampaignRepository(), new RewardRepository(), $customers ) )->for_balance( DesignHelper::get_design(), null === $customer ? 0 : $customer->get_balance(), EarningService::points_left_today( $user_id ) ),
            'expires_at' => null === $customer ? null : $customer->get_expires_at(),
            'can_earn'   => null === EarningService::gate_reason( CampaignTrigger::PURCHASE, ContextFactory::from_user( $user_id, Dates::now() ) ),
            'history'    => [
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
}
