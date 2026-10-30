<?php
namespace YayLoyalty\Models;

defined( 'ABSPATH' ) || exit;

/**
 * One reward claim: what was spent, what it bought, at what rate and on which order.
 * A refund needs these facts as they were at redemption time, never today's.
 * Written once at commit. A cancel or refund moves only `status` and `restored_points`.
 */
final class Redemption extends AbstractModel {

    /** Flexible discount lifecycle: applied (order exists, points debited) → cancelled | refunded. */
    public const STATUS_APPLIED   = 'applied';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED  = 'refunded';

    public const STORED_STATUSES = [ self::STATUS_APPLIED, self::STATUS_REFUNDED, self::STATUS_CANCELLED ];

    private ?int $id;

    private int $user_id;

    private int $reward_id;

    /** The order the discount was applied to. Nullable in the table, though CommitWriter always sets it. */
    private ?int $order_id;

    /** Points spent, positive. */
    private int $points;

    /** Discount value in `currency`. */
    private string $value;

    private string $currency;

    /** The same value in the store's base currency, for reports. */
    private string $base_value;

    /** Points per currency unit at redemption time. */
    private string $rate_snapshot;

    /** The reward's stored coupon code, the code of the virtual coupon the order carries. */
    private ?string $coupon_code;

    private string $status;

    /** Points already returned through refunds. Never exceeds `points`. */
    private int $restored_points;

    private string $created_at;

    private string $updated_at;

    public function __construct(
        ?int $id,
        int $user_id,
        int $reward_id,
        ?int $order_id,
        int $points,
        string $value,
        string $currency,
        string $base_value,
        string $rate_snapshot,
        ?string $coupon_code,
        string $status,
        int $restored_points,
        string $created_at,
        string $updated_at
    ) {
        $this->id              = $id;
        $this->user_id         = $user_id;
        $this->reward_id       = $reward_id;
        $this->order_id        = $order_id;
        $this->points          = $points;
        $this->value           = $value;
        $this->currency        = $currency;
        $this->base_value      = $base_value;
        $this->rate_snapshot   = $rate_snapshot;
        $this->coupon_code     = $coupon_code;
        $this->status          = $status;
        $this->restored_points = $restored_points;
        $this->created_at      = $created_at;
        $this->updated_at      = $updated_at;
    }

    public static function from_row( array $row ): self {
        return new self(
            self::cast_int( $row['id'] ?? null ),
            self::cast_int_default( $row['user_id'] ?? 0 ),
            self::cast_int_default( $row['reward_id'] ?? 0 ),
            self::cast_int( $row['order_id'] ?? null ),
            self::cast_int_default( $row['points'] ?? 0 ),
            self::cast_decimal( $row['value'] ?? null, '0' ),
            (string) ( $row['currency'] ?? '' ),
            self::cast_decimal( $row['base_value'] ?? null, '0' ),
            self::cast_decimal( $row['rate_snapshot'] ?? null, '0' ),
            self::cast_string( $row['coupon_code'] ?? null ),
            (string) ( $row['status'] ?? self::STATUS_APPLIED ),
            self::cast_int_default( $row['restored_points'] ?? 0 ),
            (string) ( $row['created_at'] ?? '' ),
            (string) ( $row['updated_at'] ?? '' )
        );
    }

    /** Insert payload. `id` is omitted so AUTO_INCREMENT assigns it. */
    public function to_row(): array {
        return [
            'user_id'         => $this->user_id,
            'reward_id'       => $this->reward_id,
            'order_id'        => $this->order_id,
            'points'          => $this->points,
            'value'           => $this->value,
            'currency'        => $this->currency,
            'base_value'      => $this->base_value,
            'rate_snapshot'   => $this->rate_snapshot,
            'coupon_code'     => $this->coupon_code,
            'status'          => $this->status,
            'restored_points' => $this->restored_points,
            'created_at'      => $this->created_at,
            'updated_at'      => $this->updated_at,
        ];
    }

    public function get_format(): array {
        return [ '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ];
    }

    public function to_array(): array {
        return array_merge( [ 'id' => $this->id ], $this->to_row() );
    }

    /** Clone carrying the id assigned at insert time. The only mutation this class allows. */
    public function with_id( int $id ): self {
        $clone     = clone $this;
        $clone->id = $id;

        return $clone;
    }

    public function get_id(): ?int {
        return $this->id;
    }

    public function get_user_id(): int {
        return $this->user_id;
    }

    public function get_reward_id(): int {
        return $this->reward_id;
    }

    public function get_order_id(): ?int {
        return $this->order_id;
    }

    public function get_points(): int {
        return $this->points;
    }

    public function get_value(): string {
        return $this->value;
    }

    public function get_currency(): string {
        return $this->currency;
    }

    public function get_base_value(): string {
        return $this->base_value;
    }

    public function get_rate_snapshot(): string {
        return $this->rate_snapshot;
    }

    public function get_coupon_code(): ?string {
        return $this->coupon_code;
    }

    public function get_status(): string {
        return $this->status;
    }

    public function get_restored_points(): int {
        return $this->restored_points;
    }

    public function get_created_at(): string {
        return $this->created_at;
    }

    public function get_updated_at(): string {
        return $this->updated_at;
    }
}
