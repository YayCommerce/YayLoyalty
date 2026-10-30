<?php
namespace YayLoyalty\Engine\Points;

defined( 'ABSPATH' ) || exit;

/**
 * What a campaign condition may know about the customer, as of the evaluated event. Null on the
 * Context for a guest. Only ledger rows and orders created before the event count, never its own.
 * An order's paid status is read at evaluation, so an older order paid between stage and award
 * can change the facts. That is accepted: award evaluates again and is authoritative.
 */
final class CustomerFacts {

    /** lifetime_earned, not balance: spending must not drop a customer below a threshold. */
    private int $lifetime_earned;

    private int $order_count;

    /** @var array<string, float> Keyed by currency code; no conversion. */
    private array $spend_by_currency;

    private ?int $tier_id;

    /**
     * @param array<string, float> $spend_by_currency
     */
    public function __construct( int $lifetime_earned, int $order_count, array $spend_by_currency, ?int $tier_id = null ) {
        $this->lifetime_earned   = $lifetime_earned;
        $this->order_count       = $order_count;
        $this->spend_by_currency = $spend_by_currency;
        $this->tier_id           = $tier_id;
    }

    public function get_lifetime_earned(): int {
        return $this->lifetime_earned;
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
