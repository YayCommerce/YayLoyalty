<?php
namespace YayLoyalty\Engine\Points;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the engine may know about the event. Immutable and WordPress-free, so the engine is
 * testable without a bootstrap. No actor_id: who is logged in is a ledger argument, not a scoring input.
 * `extra` holds one-trigger facts. A fact that two triggers need becomes a typed property.
 * An advertising Context (advert()) stands for "any shopper, no event"; only PointsEngine::advertised() takes one.
 */
final class Context {

    /**
     * Every reward's stored coupon code is this prefix plus COUPON_CODE_LENGTH characters of COUPON_CODE_ALPHABET. Codes of
     * that shape are our own virtual coupons and never count as "used a coupon".
     */
    public const COUPON_CODE_PREFIX   = 'yayloyalty-';
    public const COUPON_CODE_LENGTH   = 8;
    public const COUPON_CODE_ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

    /** COUPON_CODE_ALPHABET as a character class: change both together. */
    private const COUPON_CODE_PATTERN = '/^' . self::COUPON_CODE_PREFIX . '[a-z0-9]{' . self::COUPON_CODE_LENGTH . '}$/D';

    /** Order facts in `extra` for campaign conditions; strings, '' when unknown. */
    public const EXTRA_PAYMENT_METHOD   = 'payment_method';
    public const EXTRA_BILLING_COUNTRY  = 'billing_country';
    public const EXTRA_SHIPPING_COUNTRY = 'shipping_country';

    /** @var int|null Null for a guest. */
    private $user_id;

    /** @var string UTC MySQL datetime: order creation for purchases, event time otherwise. */
    private $occurred_at;

    /** @var string Order currency, e.g. 'EUR'. */
    private $currency;

    /** @var float Multiply order-currency amounts by this to reach store base currency. */
    private $to_base_factor;

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

    /** @var array<string, mixed> */
    private $extra;

    /** @var CustomerFacts|\Closure|null A Closure is the loader, replaced by its result on first read. */
    private $customer_facts;

    /** @var bool Set only by advert(). */
    private $advert = false;

    /**
     * @param ContextLineItem[]    $line_items
     * @param string[]             $coupon_codes
     * @param string[]             $user_roles
     * @param array<string, mixed> $extra
     * @param CustomerFacts|\Closure|null $customer_facts The facts, or a loader returning ?CustomerFacts,
     *                                                   run on the first get_customer_facts() so an
     *                                                   event no history condition asks about reads nothing.
     */
    public function __construct(
        ?int $user_id,
        string $occurred_at,
        string $currency,
        float $to_base_factor,
        array $line_items,
        float $order_subtotal,
        float $order_discount_total,
        float $loyalty_discount,
        array $coupon_codes,
        array $user_roles,
        ?int $order_id = null,
        ?int $comment_id = null,
        array $extra = [],
        $customer_facts = null
    ) {
        if ( null !== $customer_facts && ! $customer_facts instanceof CustomerFacts && ! $customer_facts instanceof \Closure ) {
            throw new \InvalidArgumentException( 'YayLoyalty: customer_facts must be CustomerFacts, a Closure or null.' );
        }

        $this->user_id              = $user_id;
        $this->occurred_at          = $occurred_at;
        $this->currency             = $currency;
        $this->to_base_factor       = $to_base_factor;
        $this->line_items           = array_values( $line_items );
        $this->order_subtotal       = $order_subtotal;
        $this->order_discount_total = $order_discount_total;
        $this->loyalty_discount     = $loyalty_discount;
        $this->coupon_codes         = array_values( array_map( 'strval', $coupon_codes ) );
        $this->user_roles           = array_values( array_map( 'strval', $user_roles ) );
        $this->order_id             = $order_id;
        $this->comment_id           = $comment_id;
        $this->extra                = $extra;
        $this->customer_facts       = $customer_facts;
    }

    /** A guest with an empty cart and no event, at $at (UTC MySQL), for advertising what a campaign pays. */
    public static function advert( string $at, string $currency, float $to_base_factor ): self {
        $ctx         = new self( null, $at, $currency, $to_base_factor, [], 0.0, 0.0, 0.0, [], [] );
        $ctx->advert = true;

        return $ctx;
    }

    /** Whether this stands for no event: the engine then skips the checks that need one. */
    public function is_advert(): bool {
        return $this->advert;
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

    public function get_to_base_factor(): float {
        return $this->to_base_factor;
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
     * Excludes our redemption coupon and every reward coupon, or exclude_coupon_orders would stop earning
     * for anyone who redeems.
     *
     * @return string[]
     */
    public function get_real_coupon_codes(): array {
        return array_values(
            array_filter(
                $this->coupon_codes,
                static function ( $code ): bool {
                    return ! self::is_loyalty_code( (string) $code );
                }
            )
        );
    }

    /** True for a code of a reward's stored shape: COUPON_CODE_PREFIX plus COUPON_CODE_LENGTH of COUPON_CODE_ALPHABET. */
    public static function is_loyalty_code( string $code ): bool {
        return 1 === preg_match( self::COUPON_CODE_PATTERN, $code );
    }

    /**
     * The loyalty codes (is_loyalty_code()) among $codes, in their order.
     *
     * @param array $codes Coupon codes, as WC_Cart::get_applied_coupons() returns them.
     * @return string[]
     */
    public static function loyalty_codes( array $codes ): array {
        return array_values( array_filter( array_map( 'strval', $codes ), [ self::class, 'is_loyalty_code' ] ) );
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

    /** What conditions may compare against; null means "no customer facts", which order-only conditions can still match. */
    public function get_customer_facts(): ?CustomerFacts {
        if ( $this->customer_facts instanceof \Closure ) {
            $facts                = ( $this->customer_facts )();
            $this->customer_facts = $facts instanceof CustomerFacts ? $facts : null;
        }

        return $this->customer_facts;
    }

    public function get_extra( string $key, $fallback = null ) {
        return array_key_exists( $key, $this->extra ) ? $this->extra[ $key ] : $fallback;
    }
}
