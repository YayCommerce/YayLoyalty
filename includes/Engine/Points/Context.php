<?php
namespace YayLoyalty\Engine\Points;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the engine may know about the event being scored.
 *
 * Immutable and WordPress-free. The engine never reads $wpdb, WooCommerce
 * globals, the current user or time(): whatever it needs arrives here, built
 * by ContextFactory (the one class that touches WooCommerce) or by hand in a
 * unit test. That is what keeps the engine testable without a bootstrap.
 *
 * NO actor_id. Who is logged in is a Ledger argument, not a scoring input —
 * manual adjustment, cron expiry and order-hook earning must share one code
 * path, and the engine must not know which one it is on.
 *
 * `extra` carries trigger-specific facts the generic shape has no column for.
 * In 1.0 it holds exactly one key, `verified_purchase`, for reviews. Anything
 * two triggers need becomes a real typed property instead.
 */
final class Context {

    /** The virtual coupon our own redemption applies. Never counts as "used a coupon". */
    public const REDEEM_COUPON_CODE = 'yayloyalty-redeem';

    /** @var int|null Null for a guest. */
    private $user_id;

    /** @var string UTC MySQL datetime: order creation for purchases, event time otherwise. */
    private $occurred_at;

    /** @var string Order currency, e.g. 'EUR'. */
    private $currency;

    /** @var float Multiply order-currency amounts by this to reach store base currency. */
    private $base_rate;

    /** @var ContextLineItem[] */
    private $line_items;

    /** @var float Pre-discount, excl. tax and shipping, order currency. */
    private $order_subtotal;

    /** @var float All coupons, order currency. */
    private $order_discount_total;

    /** @var float Our own redemption coupon's amount, order currency. */
    private $loyalty_discount;

    /** @var string[] Every applied code, ours included. */
    private $coupon_codes;

    /** @var string[] */
    private $user_roles;

    /** @var int|null */
    private $order_id;

    /** @var int|null */
    private $comment_id;

    /** @var int Supplied by the caller from the ledger; 0 in a preview. */
    private $points_earned_today;

    /** @var array<string, mixed> */
    private $extra;

    /**
     * @param ContextLineItem[]    $line_items
     * @param string[]             $coupon_codes
     * @param string[]             $user_roles
     * @param array<string, mixed> $extra
     */
    public function __construct(
        ?int $user_id,
        string $occurred_at,
        string $currency,
        float $base_rate,
        array $line_items,
        float $order_subtotal,
        float $order_discount_total,
        float $loyalty_discount,
        array $coupon_codes,
        array $user_roles,
        ?int $order_id = null,
        ?int $comment_id = null,
        int $points_earned_today = 0,
        array $extra = []
    ) {
        $this->user_id              = $user_id;
        $this->occurred_at          = $occurred_at;
        $this->currency             = $currency;
        $this->base_rate            = $base_rate;
        $this->line_items           = array_values( $line_items );
        $this->order_subtotal       = $order_subtotal;
        $this->order_discount_total = $order_discount_total;
        $this->loyalty_discount     = $loyalty_discount;
        $this->coupon_codes         = array_values( array_map( 'strval', $coupon_codes ) );
        $this->user_roles           = array_values( array_map( 'strval', $user_roles ) );
        $this->order_id             = $order_id;
        $this->comment_id           = $comment_id;
        $this->points_earned_today  = max( 0, $points_earned_today );
        $this->extra                = $extra;
    }

    public function get_user_id(): ?int {
        return $this->user_id;
    }

    public function get_occurred_at(): string {
        return $this->occurred_at;
    }

    public function get_currency(): string {
        return $this->currency;
    }

    public function get_base_rate(): float {
        return $this->base_rate;
    }

    /** @return ContextLineItem[] */
    public function get_line_items(): array {
        return $this->line_items;
    }

    public function get_order_subtotal(): float {
        return $this->order_subtotal;
    }

    public function get_order_discount_total(): float {
        return $this->order_discount_total;
    }

    public function get_loyalty_discount(): float {
        return $this->loyalty_discount;
    }

    /** @return string[] */
    public function get_coupon_codes(): array {
        return $this->coupon_codes;
    }

    /**
     * Applied coupons other than our own redemption coupon.
     *
     * Redeeming points must not read as "used a coupon", or a campaign with
     * exclude_coupon_orders would silently stop earning for anyone who redeems.
     *
     * @return string[]
     */
    public function get_real_coupon_codes(): array {
        return array_values( array_diff( $this->coupon_codes, [ self::REDEEM_COUPON_CODE ] ) );
    }

    /** @return string[] */
    public function get_user_roles(): array {
        return $this->user_roles;
    }

    public function get_order_id(): ?int {
        return $this->order_id;
    }

    public function get_comment_id(): ?int {
        return $this->comment_id;
    }

    public function get_points_earned_today(): int {
        return $this->points_earned_today;
    }

    /**
     * @param mixed $fallback
     * @return mixed
     */
    public function get_extra( string $key, $fallback = null ) {
        return array_key_exists( $key, $this->extra ) ? $this->extra[ $key ] : $fallback;
    }
}
