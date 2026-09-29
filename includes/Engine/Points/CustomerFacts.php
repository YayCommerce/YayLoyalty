<?php
namespace YayLoyalty\Engine\Points;

defined( 'ABSPATH' ) || exit;

/**
 * What a campaign condition may know about the customer, read by
 * CustomerFactsReader the first time a history condition asks the Context
 * (at most once per event), so the evaluator compares values and queries
 * nothing itself. Immutable. Absent (null on the Context) for a guest.
 * Identity and roles are on the Context itself; so are the order's own
 * facts (currency, coupons, lines, payment method, countries).
 *
 * As of the evaluated event. lifetime_points counts ledger rows created
 * before it, so the event's own award never feeds it. Order facts count
 * orders created before it, never its own order, whose status is paid when
 * read: the window is fixed but "paid" is not, so an older order paid
 * between stage and award can change them. Accepted: award re-evaluates and
 * is authoritative.
 */
final class CustomerFacts {

    /** lifetime_earned, not balance: spending must not drop a customer below a threshold. */
    private int $lifetime_points;

    private int $order_count;

    /** @var array<string, float> Keyed by currency code; no conversion. */
    private array $spend_by_currency;

    private ?int $tier_id;

    /**
     * @param array<string, float> $spend_by_currency
     */
    public function __construct( int $lifetime_points, int $order_count, array $spend_by_currency, ?int $tier_id = null ) {
        $this->lifetime_points   = $lifetime_points;
        $this->order_count       = $order_count;
        $this->spend_by_currency = $spend_by_currency;
        $this->tier_id           = $tier_id;
    }

    public function get_lifetime_points(): int {
        return $this->lifetime_points;
    }

    public function get_order_count(): int {
        return $this->order_count;
    }

    /** 0.0 for a currency the customer never paid in. */
    public function get_spend_in( string $currency ): float {
        return (float) ( $this->spend_by_currency[ $currency ] ?? 0.0 );
    }

    public function get_tier_id(): ?int {
        return $this->tier_id;
    }
}
