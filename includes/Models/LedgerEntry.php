<?php
namespace YayLoyalty\Models;

use YayLoyalty\Enums\LedgerType;

defined( 'ABSPATH' ) || exit;

/**
 * One balance change. Append-only fact.
 *
 * IMMUTABLE BY CONVENTION. PHP 7.4 has no readonly, so this class relies on
 * private properties, getters only, no setters, and with_*() returning a clone.
 * The convention is the enforcement — there is no language support behind it.
 * Do not add a setter here; a mutable ledger entry would let a caller rewrite
 * history that the balance_after chain depends on.
 *
 * `points` is a SIGNED delta: positive for earn, refund_redeem and a positive
 * adjust; negative for redeem, refund_earn, expire and a negative adjust.
 *
 * `balance_after` is the balance immediately following this row. Together the
 * rows form a chain per user: prev.balance_after + points === this.balance_after.
 * The audit tool walks it, so it must be computed inside the customer row lock.
 *
 * `note` is only ever set on manual adjustments. System rows deliberately store
 * no text: their description is composed at render time from type/source and
 * the reference, so it stays translatable and cannot go stale.
 */
final class LedgerEntry extends AbstractModel {

    /** @var int|null Null until inserted. */
    private $id;

    /** @var int */
    private $user_id;

    /** @var int Signed delta. */
    private $points;

    /** @var int */
    private $balance_after;

    /** @var string */
    private $type;

    /** @var string */
    private $source;

    /** @var string|null */
    private $reference_type;

    /** @var int|null */
    private $reference_id;

    /** @var int|null */
    private $campaign_id;

    /** @var int|null */
    private $redemption_id;

    /** @var int|null */
    private $order_id;

    /** @var string|null */
    private $note;

    /** @var int|null */
    private $actor_id;

    /** @var string */
    private $created_at;

    public function __construct(
        ?int $id,
        int $user_id,
        int $points,
        int $balance_after,
        string $type,
        string $source,
        ?string $reference_type = null,
        ?int $reference_id = null,
        ?int $campaign_id = null,
        ?int $redemption_id = null,
        ?int $order_id = null,
        ?string $note = null,
        ?int $actor_id = null,
        string $created_at = ''
    ) {
        $this->id             = $id;
        $this->user_id        = $user_id;
        $this->points         = $points;
        $this->balance_after  = $balance_after;
        $this->type           = $type;
        $this->source         = $source;
        $this->reference_type = $reference_type;
        $this->reference_id   = $reference_id;
        $this->campaign_id    = $campaign_id;
        $this->redemption_id  = $redemption_id;
        $this->order_id       = $order_id;
        $this->note           = $note;
        $this->actor_id       = $actor_id;
        $this->created_at     = $created_at;
    }

    public static function from_row( array $row ): self {
        return new self(
            self::cast_int( $row['id'] ?? null ),
            self::cast_int_default( $row['user_id'] ?? 0 ),
            self::cast_int_default( $row['points'] ?? 0 ),
            self::cast_int_default( $row['balance_after'] ?? 0 ),
            (string) ( $row['type'] ?? '' ),
            (string) ( $row['source'] ?? '' ),
            self::cast_string( $row['reference_type'] ?? null ),
            self::cast_int( $row['reference_id'] ?? null ),
            self::cast_int( $row['campaign_id'] ?? null ),
            self::cast_int( $row['redemption_id'] ?? null ),
            self::cast_int( $row['order_id'] ?? null ),
            self::cast_string( $row['note'] ?? null ),
            self::cast_int( $row['actor_id'] ?? null ),
            (string) ( $row['created_at'] ?? '' )
        );
    }

    /**
     * Insert payload. `id` is omitted so AUTO_INCREMENT assigns it.
     */
    public function to_row(): array {
        return [
            'user_id'        => $this->user_id,
            'points'         => $this->points,
            'balance_after'  => $this->balance_after,
            'type'           => $this->type,
            'source'         => $this->source,
            'reference_type' => $this->reference_type,
            'reference_id'   => $this->reference_id,
            'campaign_id'    => $this->campaign_id,
            'redemption_id'  => $this->redemption_id,
            'order_id'       => $this->order_id,
            'note'           => $this->note,
            'actor_id'       => $this->actor_id,
            'created_at'     => $this->created_at,
        ];
    }

    public function get_format(): array {
        return [ '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%d', '%s' ];
    }

    public function to_array(): array {
        return [
            'id'             => $this->id,
            'user_id'        => $this->user_id,
            'points'         => $this->points,
            'balance_after'  => $this->balance_after,
            'type'           => $this->type,
            'source'         => $this->source,
            'reference_type' => $this->reference_type,
            'reference_id'   => $this->reference_id,
            'campaign_id'    => $this->campaign_id,
            'redemption_id'  => $this->redemption_id,
            'order_id'       => $this->order_id,
            // Only adjust rows carry stored text; everything else is composed
            // client-side from type/source so it stays translatable.
            'note'           => LedgerType::ADJUST === $this->type ? $this->note : null,
            'actor_id'       => $this->actor_id,
            'created_at'     => $this->created_at,
        ];
    }

    /**
     * Clone carrying the values assigned at insert time.
     *
     * The only "mutation" this class allows, and it returns a new instance.
     */
    public function with_persisted( int $id, int $balance_after ): self {
        $clone                = clone $this;
        $clone->id            = $id;
        $clone->balance_after = $balance_after;

        return $clone;
    }

    public function get_id(): ?int {
        return $this->id;
    }

    public function get_user_id(): int {
        return $this->user_id;
    }

    public function get_points(): int {
        return $this->points;
    }

    public function get_balance_after(): int {
        return $this->balance_after;
    }

    public function get_type(): string {
        return $this->type;
    }

    public function get_source(): string {
        return $this->source;
    }

    public function get_reference_type(): ?string {
        return $this->reference_type;
    }

    public function get_reference_id(): ?int {
        return $this->reference_id;
    }

    public function get_note(): ?string {
        return $this->note;
    }

    public function get_actor_id(): ?int {
        return $this->actor_id;
    }

    public function get_created_at(): string {
        return $this->created_at;
    }
}
