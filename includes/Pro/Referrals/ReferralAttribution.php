<?php
namespace YayLoyalty\Pro\Referrals;

use YayLoyalty\Engine\Earning\Listeners\ListenerGuard;
use YayLoyalty\Engine\Earning\Listeners\SignupListener;
use YayLoyalty\Pro\Repositories\ReferralRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Credits a new account to the customer whose link brought the visitor: the link sets a cookie,
 * and a self-registration inside its window becomes a pending referral with the terms in force then.
 * Logged-in visitors and existing accounts are never attributed.
 */
final class ReferralAttribution {

    public const QUERY_ARG = 'yl_ref';

    public const COOKIE = 'yayloyalty_ref';

    /** Separates the code from the visit's Unix time in the cookie, so the window is checked against today's setting. */
    private const COOKIE_SEPARATOR = '.';

    /** The shape a customer's code may take; anything else is ignored before it reaches the database. */
    private const CODE_PATTERN = '/^[A-Za-z0-9-]{4,32}$/D';

    private const SOURCE = 'ReferralAttribution';

    private CustomerRepository $customers;

    private ReferralRepository $referrals;

    public function __construct( CustomerRepository $customers, ReferralRepository $referrals ) {
        $this->customers = $customers;
        $this->referrals = $referrals;
    }

    public function register(): void {
        // Before core's canonical and old-slug redirects, which drop the query string and exit.
        add_action( 'template_redirect', [ $this, 'on_template_redirect' ], 0 );
        // After CustomerActivityListener (10) creates the friend's member row.
        add_action( 'user_register', [ $this, 'on_user_register' ], 20, 1 );
    }

    /** Remembers the advocate's code for a visitor who opened a referral link. */
    public function on_template_redirect(): void {
        if ( ! isset( $_GET[ self::QUERY_ARG ] ) || is_user_logged_in() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a link anyone may open; it only sets a cookie.
            return;
        }

        $code = self::clean_code( wp_unslash( $_GET[ self::QUERY_ARG ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- clean_code() sanitizes and validates it.

        ListenerGuard::run(
            self::SOURCE,
            'link capture failed',
            function () use ( $code ): void {
                $settings = ReferralSettings::get();

                if ( null === $code || ! $settings['enabled'] || null === $this->customers->find_user_by_referral_code( $code ) ) {
                    return;
                }

                self::set_cookie( $code . self::COOKIE_SEPARATOR . time(), time() + (int) $settings['cookie_days'] * DAY_IN_SECONDS );
            }
        );
    }

    /** Records a self-registered visitor who arrived through a referral link as a pending referral. */
    public function on_user_register( int $user_id ): void {
        if ( ! isset( $_COOKIE[ self::COOKIE ] ) || ! SignupListener::is_self_registration() ) {
            return;
        }

        $raw                       = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
        list( $code, $visited_at ) = array_pad( explode( self::COOKIE_SEPARATOR, $raw, 2 ), 2, '' );

        ListenerGuard::run(
            self::SOURCE,
            'attribution failed for user #' . $user_id,
            function () use ( $user_id, $code, $visited_at ): void {
                $this->attribute( $user_id, self::clean_code( $code ), ctype_digit( $visited_at ) ? (int) $visited_at : 0 );
            }
        );
    }

    private function attribute( int $friend_user_id, ?string $code, int $visited_at ): void {
        try {
            $settings    = ReferralSettings::get();
            $in_window   = time() - $visited_at <= (int) $settings['cookie_days'] * DAY_IN_SECONDS;
            $advocate_id = null === $code || ! $in_window ? null : $this->customers->find_user_by_referral_code( $code );

            if ( null !== $advocate_id && $settings['enabled'] && $advocate_id !== $friend_user_id && null === $this->referrals->find_by_friend( $friend_user_id ) ) {
                $this->referrals->insert_pending(
                    $advocate_id,
                    $friend_user_id,
                    (string) $code,
                    self::terms( $settings ),
                    Dates::offset( (int) $settings['coupon_days'] * DAY_IN_SECONDS )
                );
            }
        } finally {
            // Spent whatever happened: a second account from this browser is not a second referral.
            self::set_cookie( '', time() - HOUR_IN_SECONDS );
        }
    }

    /** Sets or clears the referral cookie: HttpOnly, secure on HTTPS, and SameSite=Lax, which wc_setcookie() leaves out. */
    private static function set_cookie( string $value, int $expires ): void {
        $lax = static function ( array $options, string $name ): array {
            return self::COOKIE === $name ? array_merge( $options, [ 'samesite' => 'Lax' ] ) : $options;
        };

        add_filter( 'woocommerce_set_cookie_options', $lax, 10, 2 );
        wc_setcookie( self::COOKIE, $value, $expires, is_ssl(), true );
        remove_filter( 'woocommerce_set_cookie_options', $lax, 10 );
    }

    /**
     * The settings a referral keeps for life.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private static function terms( array $settings ): array {
        return [
            'discount_type'         => $settings['discount_type'],
            'discount_amount'       => $settings['discount_amount'],
            'min_order'             => $settings['min_order'],
            'currency'              => (string) get_option( 'woocommerce_currency' ),
            'advocate_points'       => $settings['advocate_points'],
            'hold_days'             => $settings['hold_days'],
            'exclude_other_coupons' => $settings['exclude_other_coupons'],
        ];
    }

    /** @param mixed $raw */
    private static function clean_code( $raw ): ?string {
        // Codes are stored upper case; the collation would match any casing, the snapshot should not differ.
        $code = is_string( $raw ) ? strtoupper( sanitize_text_field( $raw ) ) : '';

        return 1 === preg_match( self::CODE_PATTERN, $code ) ? $code : null;
    }
}
