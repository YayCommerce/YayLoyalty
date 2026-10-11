<?php
namespace YayLoyalty\Models;

use YayLoyalty\Enums\LedgerType;

defined( 'ABSPATH' ) || exit;

/**
 * One balance change, append-only. Immutable by convention (no setters,
 * with_*() clones): a mutable entry could rewrite the balance_after chain,
 * which must be computed inside the customer row lock. `note` is set only
 * on manual adjustments. System rows compose their text at render time so
 * it stays translatable.
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

    /** @var string|null Shown to the customer in place of "Adjustment by {store}"; manual adjustments only. */
    private $customer_note;

    /** @var int|null */
    private $actor_id;

    /** @var string|null Engine\Earning\IdempotencyKey string for system rows, or null for manual adjustments. */
    private $idempotency_key;

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
        string $created_at = '',
        ?string $idempotency_key = null,
        ?string $customer_note = null
    ) {
        $this->id              = $id;
        $this->user_id         = $user_id;
        $this->points          = $points;
        $this->balance_after   = $balance_after;
        $this->type            = $type;
        $this->source          = $source;
        $this->reference_type  = $reference_type;
        $this->reference_id    = $reference_id;
        $this->campaign_id     = $campaign_id;
        $this->redemption_id   = $redemption_id;
        $this->order_id        = $order_id;
        $this->note            = $note;
        $this->actor_id        = $actor_id;
        $this->created_at      = $created_at;
        $this->idempotency_key = $idempotency_key;
        $this->customer_note   = $customer_note;
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
            (string) ( $row['created_at'] ?? '' ),
            self::cast_string( $row['idempotency_key'] ?? null ),
            self::cast_string( $row['customer_note'] ?? null )
        );
    }

    /** Insert payload. `id` is omitted so AUTO_INCREMENT assigns it. */
    public function to_row(): array {
        return [
            'user_id'         => $this->user_id,
            'points'          => $this->points,
            'balance_after'   => $this->balance_after,
            'type'            => $this->type,
            'source'          => $this->source,
            'reference_type'  => $this->reference_type,
            'reference_id'    => $this->reference_id,
            'campaign_id'     => $this->campaign_id,
            'redemption_id'   => $this->redemption_id,
            'order_id'        => $this->order_id,
            'note'            => $this->note,
            'customer_note'   => $this->customer_note,
            'actor_id'        => $this->actor_id,
            'idempotency_key' => $this->idempotency_key,
            'created_at'      => $this->created_at,
        ];
    }

    public function get_format(): array {
        return [ '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s' ];
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
            'note'           => LedgerType::is_manual_adjustment( $this->type ) ? $this->note : null,
            'actor_id'       => $this->actor_id,
            'created_at'     => $this->created_at,
        ];
    }

    /** Clone with another amount, for a writer that can only decide it under the customer lock. */
    public function with_points( int $points ): self {
        $clone         = clone $this;
        $clone->points = $points;

        return $clone;
    }

    /** Clone carrying the values assigned at insert time. */
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

    public function get_campaign_id(): ?int {
        return $this->campaign_id;
    }

    public function get_order_id(): ?int {
        return $this->order_id;
    }

    public function get_note(): ?string {
        return $this->note;
    }

    public function get_customer_note(): ?string {
        return $this->customer_note;
    }

    public function get_actor_id(): ?int {
        return $this->actor_id;
    }

    public function get_created_at(): string {
        return $this->created_at;
    }

    /** Null for manual adjustments, which are deliberately not idempotent. */
    public function get_idempotency_key(): ?string {
        return $this->idempotency_key;
    }
}
