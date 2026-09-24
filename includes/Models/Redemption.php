<?php
namespace YayLoyalty\Models;

defined( 'ABSPATH' ) || exit;

/**
 * One reward claim: what was spent, what it bought, at what rate, on which
 * order — the facts a refund needs later (value and rate at redemption
 * time, never today's). Written once at commit; `status` and
 * `restored_points` are the only columns Zone 3 will move. Money is a
 * decimal string per docs/code-standards.md §5.
 */
final class Redemption extends AbstractModel {

    /** Flexible discount lifecycle: applied (order exists, points debited) → completed | cancelled | refunded. */
    public const STATUS_APPLIED   = 'applied';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED  = 'refunded';

    /** Catalog coupon lifecycle (Pro): issued → used | expired | cancelled. */
    public const STATUS_ISSUED  = 'issued';
    public const STATUS_USED    = 'used';
    public const STATUS_EXPIRED = 'expired';

    private ?int $id;

    private int $user_id;

    private int $reward_id;

    /** Null for a catalog coupon not yet used. */
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

    /** `yayloyalty-redeem` for the virtual coupon; the issued code for catalog rewards. */
    private ?string $coupon_code;

    /** `shop_coupon` post id for catalog rewards; null for the virtual coupon. */
    private ?int $coupon_id;

    private string $status;

    /** Points already returned through refunds; never exceeds `points`. */
    private int $restored_points;

    private ?string $expires_at;

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
        ?int $coupon_id,
        string $status,
        int $restored_points,
        ?string $expires_at,
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
        $this->coupon_id       = $coupon_id;
        $this->status          = $status;
        $this->restored_points = $restored_points;
        $this->expires_at      = $expires_at;
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
            self::cast_int( $row['coupon_id'] ?? null ),
            (string) ( $row['status'] ?? self::STATUS_APPLIED ),
            self::cast_int_default( $row['restored_points'] ?? 0 ),
            self::cast_datetime( $row['expires_at'] ?? null ),
            (string) ( $row['created_at'] ?? '' ),
            (string) ( $row['updated_at'] ?? '' )
        );
    }

    /** Insert payload; `id` is omitted so AUTO_INCREMENT assigns it. */
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
            'coupon_id'       => $this->coupon_id,
            'status'          => $this->status,
            'restored_points' => $this->restored_points,
            'expires_at'      => $this->expires_at,
            'created_at'      => $this->created_at,
            'updated_at'      => $this->updated_at,
        ];
    }

    public function get_format(): array {
        return [ '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s' ];
    }

    public function to_array(): array {
        return array_merge( [ 'id' => $this->id ], $this->to_row() );
    }

    /** Clone carrying the id assigned at insert time; the only mutation this class allows. */
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

    public function get_coupon_id(): ?int {
        return $this->coupon_id;
    }

    public function get_status(): string {
        return $this->status;
    }

    public function get_restored_points(): int {
        return $this->restored_points;
    }

    public function get_expires_at(): ?string {
        return $this->expires_at;
    }

    public function get_created_at(): string {
        return $this->created_at;
    }

    public function get_updated_at(): string {
        return $this->updated_at;
    }
}
