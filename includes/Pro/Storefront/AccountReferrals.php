<?php
namespace YayLoyalty\Pro\Storefront;

use YayLoyalty\Engine\Earning\ProgramGate;
use YayLoyalty\Engine\Register\ScriptName;
use YayLoyalty\Engine\Storefront\AccountMyPoints;
use YayLoyalty\Engine\Storefront\Launcher;
use YayLoyalty\Engine\Storefront\LauncherPayload;
use YayLoyalty\Engine\Storefront\OutputCapture;
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

    private const STYLE_PATH = 'includes/Pro/Storefront/assets/account-referrals.css';

    private const SCRIPT_PATH = 'includes/Pro/Storefront/assets/referral-link.js';

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
        // Plain permalinks have no rules to check.
        if ( '' === (string) get_option( 'permalink_structure' ) ) {
            return;
        }

        // A permalink save while Pro was off drops the endpoint's rule but keeps the stamp, so check the rule too.
        if ( get_option( self::OPTION_REWRITE_VERSION ) === self::REWRITE_VERSION ) {
            foreach ( array_keys( (array) get_option( 'rewrite_rules', [] ) ) as $rule ) {
                if ( false !== strpos( (string) $rule, '/' . self::ENDPOINT . '(/(.*))?/?$' ) ) {
                    return;
                }
            }
        }

        flush_rewrite_rules( false );
        update_option( self::OPTION_REWRITE_VERSION, self::REWRITE_VERSION );
    }

    /** Whether the viewer gets the tab: the program is live and they can earn (not opted out, not an excluded role). */
    public static function is_available( int $user_id ): bool {
        return $user_id > 0 && ReferralSettings::get()['enabled'] && ProgramGate::storefront_allowed() && Launcher::viewer_can_earn( $user_id );
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

    /** With the program off, or for a customer who can't earn, the tab's URL lands on the account dashboard. A guest gets the login form. */
    public static function redirect_when_unavailable(): void {
        if ( is_user_logged_in() && is_wc_endpoint_url( self::ENDPOINT ) && ! self::is_available( get_current_user_id() ) ) {
            wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
            exit;
        }
    }

    public static function enqueue(): void {
        if ( ! is_wc_endpoint_url( self::ENDPOINT ) ) {
            return;
        }

        wp_enqueue_style( ScriptName::ACCOUNT_STYLE );
        wp_enqueue_style( self::STYLE_HANDLE, YAYLOYALTY_PLUGIN_URL . self::STYLE_PATH, [ ScriptName::ACCOUNT_STYLE ], Launcher::asset_version( self::STYLE_PATH ) );
        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            YAYLOYALTY_PLUGIN_URL . self::SCRIPT_PATH,
            [],
            Launcher::asset_version( self::SCRIPT_PATH ),
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

        $html = OutputCapture::capture(
            static function () use ( $user_id, $value ): void {
                $view = self::view( $user_id, max( 1, absint( $value ) ) );

                include __DIR__ . '/templates/account-referrals.php';
            },
            'AccountReferrals'
        );

        if ( null === $html ) {
            $title = __( 'Referrals are currently unavailable', 'yayloyalty' );
            $text  = __( 'We couldn’t load your referrals. Please try again later.', 'yayloyalty' );

            include YAYLOYALTY_PLUGIN_DIR . 'includes/Engine/Storefront/templates/account-rewards-state.php';

            return;
        }

        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the template escapes every value.
    }

    /**
     * @return array{settings: array<string, mixed>, link: string, counts: array<string, mixed>, worth: ?float, earned_worth: ?float, page: array{items: array, total: int, page: int, pages: int}}
     */
    private static function view( int $user_id, int $page ): array {
        $settings = ReferralSettings::get();
        $counts   = ( new ReferralListRepository() )->counts_for_advocate( $user_id );
        $reward   = ( new RewardRepository() )->find_live_flexible_discount( Dates::now() );
        $worth    = static fn( int $points ): ?float => null === $reward || $points < 1 ? null : LauncherPayload::worth( $reward, $points );
        $pages    = max( 1, (int) ceil( $counts['referred'] / self::PER_PAGE ) );
        // A stale page link past the end shows the last page, not an empty list.
        $page = min( $page, $pages );
        $rows = ( new ReferralListRepository() )->paginate(
            [
                'advocate' => $user_id,
                'page'     => $page,
                'per_page' => self::PER_PAGE,
            ]
        );

        return [
            'settings'     => $settings,
            'link'         => ReferralAttribution::link_for( ( new CustomerRepository() )->assign_referral_code( $user_id ) ),
            'counts'       => $counts,
            'worth'        => $worth( (int) $settings['advocate_points'] ),
            'earned_worth' => $worth( (int) $counts['points_earned'] ),
            'page'         => [
                'items' => $rows['items'],
                'total' => (int) $rows['total'],
                'page'  => $page,
                'pages' => $pages,
            ],
        ];
    }
}
