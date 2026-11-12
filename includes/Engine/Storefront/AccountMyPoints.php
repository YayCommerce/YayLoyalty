<?php
namespace YayLoyalty\Engine\Storefront;

use YayLoyalty\Controllers\Schemas\LedgerQueryArgs;
use YayLoyalty\Engine\Earning\ProgramGate;
use YayLoyalty\Engine\Register\ScriptName;
use YayLoyalty\Enums\TierMode;
use YayLoyalty\Helpers\DesignHelper;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * The My Account "My Points" tab: the shopper's balance, the programme's rules and their points history.
 */
final class AccountMyPoints {

    public const ENDPOINT = 'my-points';

    /** Bump when the endpoint's rewrite rules change, so every site flushes once. */
    public const REWRITE_VERSION = '2';

    public const OPTION_REWRITE_VERSION = 'yayloyalty_rewrite_version';

    public const PER_PAGE = 20;

    public const FILTER_QUERY_ARG = 'points_type';

    /** The id of the tab's "Points history" section, so a link can land on it. */
    public const HISTORY_ANCHOR = 'yayloyalty-points-history';

    /** Filters the member's "Your tier" section; see member_tier(). */
    public const MEMBER_TIER_FILTER = 'yayloyalty_account_member_tier';

    /** The one file enqueued: it holds the tokens and @imports each section's file beside it. */
    private const STYLE_PATH = 'assets/storefront/account/rewards-tokens.css';

    /** Swaps the history in place when a filter tab or page link is picked. */
    private const HISTORY_SCRIPT_PATH = 'assets/storefront/points-history.js';

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
        add_action( 'wc_ajax_' . AccountHistoryAjax::ACTION, [ AccountHistoryAjax::class, 'respond' ] );
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
        return Launcher::versioned_url( self::STYLE_PATH );
    }

    public static function enqueue(): void {
        if ( is_wc_endpoint_url( self::ENDPOINT ) ) {
            wp_enqueue_style( ScriptName::ACCOUNT_STYLE );
            wp_enqueue_script(
                ScriptName::ACCOUNT_HISTORY_SCRIPT,
                YAYLOYALTY_PLUGIN_URL . self::HISTORY_SCRIPT_PATH,
                [],
                Launcher::asset_version( self::HISTORY_SCRIPT_PATH ),
                [
                    'in_footer' => true,
                    'strategy'  => 'defer',
                ]
            );
        }
    }

    public static function title(): string {
        return __( 'My Points', 'yayloyalty' );
    }

    /** The tab's URL: the page as the endpoint value, the history filter as `points_type`. */
    public static function url( int $page = 1, string $filter = 'all' ): string {
        $url = wc_get_endpoint_url( self::ENDPOINT, $page > 1 ? (string) $page : '', wc_get_page_permalink( 'myaccount' ) );

        return 'all' === $filter ? $url : add_query_arg( self::FILTER_QUERY_ARG, $filter, $url );
    }

    /** The tab's URL scrolled to its "Points history" section. */
    public static function history_url(): string {
        return self::url() . '#' . self::HISTORY_ANCHOR;
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
            'points'      => SettingsHelper::points_label_plural(),
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
            'AccountMyPoints'
        );

        if ( null === $html ) {
            self::print_state( __( 'Rewards are currently unavailable', 'yayloyalty' ), __( 'We couldn’t load your rewards. Please try again later.', 'yayloyalty' ) );

            return;
        }

        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the template escapes every value.
    }

    /**
     * The points card's data: the payload for this viewer.
     *
     * @param int $user_id The viewer; 0 for a guest.
     * @return array{payload: array}
     */
    public static function card_view( int $user_id ): array {
        return [ 'payload' => LauncherPayload::for_user( DesignHelper::get_design(), $user_id ) ];
    }

    /**
     * @return array{payload: array, earn: array, tier: array|null, history: array{rows: array, total: int, page: int, filter: string}}
     */
    private static function view( int $user_id, int $page ): array {
        $card = self::card_view( $user_id );

        return $card + [
            'earn'    => LauncherEarnRules::for_viewer( $card['payload'], $user_id ),
            'tier'    => self::member_tier( $user_id ),
            'history' => self::history_view( $user_id, $page ),
        ];
    }

    /**
     * The "Your tier" section's data, or null to print none. A throwing subscriber or a malformed answer is
     * dropped with a warning, so the rest of the tab still renders.
     */
    private static function member_tier( int $user_id ): ?array {
        try {
            /**
             * Filters the member's "Your tier" section on the My Points tab. Internal seam for Pro, not a public API.
             *
             * Return null to print nothing, or an array with:
             * - name, color:   ?string, the member's tier and its '#RRGGBB'; null when they have none.
             * - multiplier:    ?string, the tier's earn multiplier as a decimal ("1.50"); null when they have none.
             * - achieved_at:   ?string, UTC MySQL datetime the tier was reached; null when they have none.
             * - next:          ?array{name: string, threshold: int}, the next tier; null at the top tier.
             * - progress:      int, the qualifying total shown.
             * - needed:        int, max( 0, next threshold - progress ); 0 when next is null.
             * - window_months: ?int, the qualifying window in months; null outside a rolling window.
             * - period:        ?string, the calendar period whose points count ('month', 'quarter' or 'year'); null
             *                  outside Calendar.
             * - held_until:    ?string, UTC MySQL datetime the tier is kept until; null when not held.
             * - tiers:         non-empty list of array{name: string, color: string, threshold: int, multiplier: string,
             *                  entry_bonus: int, is_current: bool}, in rank order; at most one is_current.
             *
             * @param array|null $tier    Null.
             * @param int        $user_id The member.
             */
            $tier = apply_filters( self::MEMBER_TIER_FILTER, null, $user_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant holds the prefixed name; the sniff cannot read it.
        } catch ( \Throwable $e ) {
            Log::warning( 'AccountMyPoints', 'member tier failed: ' . $e->getMessage() );

            return null;
        }

        if ( null === $tier ) {
            return null;
        }

        if ( ! self::is_member_tier( $tier ) ) {
            Log::warning( 'AccountMyPoints', 'member tier malformed' );

            return null;
        }

        $tiers = [];

        foreach ( $tier['tiers'] as $row ) {
            $tiers[] = [
                'name'        => $row['name'],
                'color'       => $row['color'],
                'threshold'   => $row['threshold'],
                'multiplier'  => $row['multiplier'],
                'entry_bonus' => $row['entry_bonus'],
                'is_current'  => $row['is_current'],
            ];
        }

        return [
            'name'          => $tier['name'],
            'color'         => $tier['color'],
            'multiplier'    => $tier['multiplier'],
            'achieved_at'   => $tier['achieved_at'],
            'next'          => null === $tier['next'] ? null : [
                'name'      => $tier['next']['name'],
                'threshold' => $tier['next']['threshold'],
            ],
            'progress'      => $tier['progress'],
            'needed'        => $tier['needed'],
            'window_months' => $tier['window_months'],
            'period'        => $tier['period'],
            'held_until'    => $tier['held_until'],
            'tiers'         => $tiers,
        ];
    }

    /** @param mixed $tier A MEMBER_TIER_FILTER answer. */
    private static function is_member_tier( $tier ): bool {
        if ( ! is_array( $tier ) ) {
            return false;
        }

        foreach ( [ 'name', 'color', 'multiplier', 'achieved_at', 'next', 'progress', 'needed', 'window_months', 'period', 'held_until', 'tiers' ] as $key ) {
            if ( ! array_key_exists( $key, $tier ) ) {
                return false;
            }
        }

        $next  = $tier['next'];
        $valid = ( null === $tier['name'] || is_string( $tier['name'] ) )
            && ( null === $tier['color'] || self::is_color( $tier['color'] ) )
            && ( null === $tier['multiplier'] || self::is_multiplier( $tier['multiplier'] ) )
            && ( null === $tier['achieved_at'] || ( is_string( $tier['achieved_at'] ) && Dates::is_valid( $tier['achieved_at'] ) ) )
            && ( null === $next || ( is_array( $next ) && isset( $next['name'], $next['threshold'] ) && is_string( $next['name'] ) && '' !== $next['name'] && is_int( $next['threshold'] ) && $next['threshold'] >= 0 ) )
            && is_int( $tier['progress'] ) && $tier['progress'] >= 0
            && is_int( $tier['needed'] ) && $tier['needed'] >= 0
            && ( null === $tier['window_months'] || ( is_int( $tier['window_months'] ) && $tier['window_months'] >= 1 ) )
            && ( null === $tier['period'] || TierMode::is_calendar_period( $tier['period'] ) )
            && ( null === $tier['held_until'] || ( is_string( $tier['held_until'] ) && Dates::is_valid( $tier['held_until'] ) ) )
            && is_array( $tier['tiers'] ) && [] !== $tier['tiers'] && array_values( $tier['tiers'] ) === $tier['tiers'];

        if ( ! $valid ) {
            return false;
        }

        $current = 0;

        foreach ( $tier['tiers'] as $row ) {
            if ( ! is_array( $row ) || ! isset( $row['name'], $row['color'], $row['threshold'], $row['multiplier'], $row['entry_bonus'], $row['is_current'] )
                || ! is_string( $row['name'] ) || ! self::is_color( $row['color'] ) || ! is_int( $row['threshold'] ) || $row['threshold'] < 0
                || ! self::is_multiplier( $row['multiplier'] ) || ! is_int( $row['entry_bonus'] ) || $row['entry_bonus'] < 0 || ! is_bool( $row['is_current'] ) ) {
                return false;
            }

            $current += $row['is_current'] ? 1 : 0;
        }

        return $current <= 1;
    }

    /**
     * A '#RRGGBB' colour; it is printed into a style attribute, where esc_attr() can't stop a CSS declaration.
     *
     * @param mixed $color
     */
    private static function is_color( $color ): bool {
        return is_string( $color ) && 1 === preg_match( '/^#[0-9A-Fa-f]{6}$/', $color );
    }

    /**
     * A decimal string of at least 1.00, as Tier stores it.
     *
     * @param mixed $multiplier
     */
    private static function is_multiplier( $multiplier ): bool {
        return is_string( $multiplier ) && is_numeric( $multiplier ) && (float) $multiplier >= 1.0;
    }

    /**
     * The history partial's data, for the tab and AccountHistoryAjax. The filter comes from `points_type`.
     *
     * @return array{rows: array, total: int, page: int, filter: string}
     */
    public static function history_view( int $user_id, int $page ): array {
        $filter  = self::current_filter();
        $history = ( new LedgerRepository() )->paginate_for_user(
            $user_id,
            [
                'types'    => LedgerQueryArgs::types_of( $filter ),
                'page'     => $page,
                'per_page' => self::PER_PAGE,
            ]
        );

        return [
            'rows'   => ShopperActivityRow::history_rows( $history['items'] ),
            'total'  => (int) $history['total'],
            'page'   => $page,
            'filter' => $filter,
        ];
    }

    /** The requested history filter; an unknown one falls back to all. */
    private static function current_filter(): string {
        $filter = isset( $_GET[ self::FILTER_QUERY_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::FILTER_QUERY_ARG ] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only view filter.

        return array_key_exists( $filter, self::filter_labels() ) ? $filter : 'all';
    }

    /** The tab's whole body when it has nothing to show: a heading and one line in a card. */
    private static function print_state( string $title, string $text ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- the template reads both.
        include __DIR__ . '/templates/account-rewards-state.php';
    }

    /** The tab's intro line, above its content; the unavailable card goes without it. */
    public static function intro(): string {
        return __( 'Your points, perks, and activity in one place.', 'yayloyalty' );
    }
}
