<?php
namespace YayLoyalty\Pro\Storefront;

use YayLoyalty\Engine\Register\ScriptName;
use YayLoyalty\Engine\Storefront\AccountMyPoints;
use YayLoyalty\Engine\Storefront\Launcher;
use YayLoyalty\Engine\Storefront\LauncherPayload;
use YayLoyalty\Pro\Referrals\ReferralAttribution;
use YayLoyalty\Pro\Referrals\ReferralSettings;
use YayLoyalty\Pro\Repositories\ReferralListRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/** The My Account "Referrals" tab: the offer, the customer's link, their counts and the friends they invited. */
final class AccountReferrals {

    public const ENDPOINT = 'referrals';

    /** Bump when the endpoint's rewrite rules change, so every site flushes once. */
    public const REWRITE_VERSION = '1';

    public const OPTION_REWRITE_VERSION = 'yayloyalty_pro_rewrite_version';

    public const PER_PAGE = 10;

    private const STYLE_HANDLE = 'yayloyalty/style/account-referrals';

    private const SCRIPT_HANDLE = 'yayloyalty/script/referral-link';

    /** Hooked on every request: a permalink save in wp-admin rebuilds the rules and must still see the endpoint. */
    public static function register(): void {
        add_filter( 'woocommerce_get_query_vars', [ self::class, 'add_query_var' ] );
        add_action( 'init', [ self::class, 'maybe_flush_rewrite_rules' ], 99 );
        // After AccountMyPoints adds My Points, so the tab can follow it.
        add_filter( 'woocommerce_account_menu_items', [ self::class, 'add_menu_item' ], 20 );
        add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', [ self::class, 'title' ] );
        add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', [ self::class, 'render' ] );
        add_action( 'template_redirect', [ self::class, 'redirect_when_unavailable' ] );
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

    /** Whether the viewer gets the tab: the program is live and they can earn (not opted out, not an excluded role). */
    public static function is_available( int $user_id ): bool {
        return $user_id > 0 && ReferralSettings::get()['enabled'] && Launcher::viewer_can_earn( $user_id );
    }

    /**
     * After My Points (Rewards), else before "Account details", else last.
     *
     * @param array<string, string> $items
     * @return array<string, string>
     */
    public static function add_menu_item( array $items ): array {
        if ( ! self::is_available( get_current_user_id() ) ) {
            return $items;
        }

        $keys     = array_keys( $items );
        $after    = array_search( AccountMyPoints::ENDPOINT, $keys, true );
        $before   = array_search( 'edit-account', $keys, true );
        $position = false !== $after ? $after + 1 : ( false !== $before ? $before : count( $items ) );

        return array_slice( $items, 0, (int) $position, true ) + [ self::ENDPOINT => self::title() ] + array_slice( $items, (int) $position, null, true );
    }

    public static function title(): string {
        return __( 'Referrals', 'yayloyalty' );
    }

    /** The tab's URL, with the invitations page as the endpoint value. */
    public static function url( int $page = 1 ): string {
        return wc_get_endpoint_url( self::ENDPOINT, $page > 1 ? (string) $page : '', wc_get_page_permalink( 'myaccount' ) );
    }

    /** With the program off, or for a customer who can't earn, the tab's URL lands on the account dashboard. */
    public static function redirect_when_unavailable(): void {
        if ( is_wc_endpoint_url( self::ENDPOINT ) && ! self::is_available( get_current_user_id() ) ) {
            wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
            exit;
        }
    }

    public static function enqueue(): void {
        if ( ! is_wc_endpoint_url( self::ENDPOINT ) ) {
            return;
        }

        $css = 'includes/Pro/Storefront/assets/account-referrals.css';
        $js  = 'includes/Pro/Storefront/assets/referral-link.js';

        wp_enqueue_style( ScriptName::ACCOUNT_STYLE );
        wp_enqueue_style( self::STYLE_HANDLE, plugins_url( 'assets/account-referrals.css', __FILE__ ), [ ScriptName::ACCOUNT_STYLE ], Launcher::asset_version( $css ) );
        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            plugins_url( 'assets/referral-link.js', __FILE__ ),
            [],
            Launcher::asset_version( $js ),
            [
                'in_footer' => true,
                'strategy'  => 'defer',
            ]
        );
    }

    /** @param string $value The endpoint value: the invitations page number. */
    public static function render( $value = '' ): void {
        $user_id = get_current_user_id();

        if ( ! self::is_available( $user_id ) ) {
            return;
        }

        $view = self::view( $user_id, max( 1, absint( $value ) ) );

        include __DIR__ . '/templates/account-referrals.php';
    }

    /**
     * @return array{settings: array<string, mixed>, link: string, counts: array<string, mixed>, worth_points: callable, page: array{items: array, total: int, page: int}}
     */
    private static function view( int $user_id, int $page ): array {
        $list   = new ReferralListRepository();
        $code   = ( new CustomerRepository() )->assign_referral_code( $user_id );
        $reward = ( new RewardRepository() )->find_live_flexible_discount( Dates::now() );
        $rows   = $list->paginate(
            [
                'advocate' => $user_id,
                'page'     => $page,
                'per_page' => self::PER_PAGE,
            ]
        );

        return [
            'settings'     => ReferralSettings::get(),
            'link'         => add_query_arg( ReferralAttribution::QUERY_ARG, rawurlencode( $code ), home_url( '/' ) ),
            'counts'       => $list->counts_for_advocate( $user_id ),
            'worth_points' => static fn( int $points ): ?float => null === $reward || $points < 1 ? null : LauncherPayload::worth( $reward, $points ),
            'page'         => [
                'items' => $rows['items'],
                'total' => (int) $rows['total'],
                'page'  => $page,
            ],
        ];
    }
}
