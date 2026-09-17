<?php
namespace YayLoyalty\Models;

defined( 'ABSPATH' ) || exit;

/**
 * A member row: cached aggregates over that user's ledger.
 *
 * Mutable, unlike LedgerEntry — this is a cache, and every value on it can be
 * rebuilt by replaying the ledger.
 *
 * `balance` and `lifetime_earned` are SIGNED. A clawback after a refund can
 * legitimately push a balance below zero, and there is deliberately no guard
 * against it: the dashboard flags negatives, the service does not prevent them.
 * `pending_points` and `lifetime_redeemed` are unsigned.
 */
class Customer extends AbstractModel {

    /** @var int */
    private $user_id;

    /** @var int Signed. */
    private $balance;

    /** @var int */
    private $pending_points;

    /** @var int Signed. */
    private $lifetime_earned;

    /** @var int */
    private $lifetime_redeemed;

    /** @var string|null */
    private $last_activity_at;

    /** @var string|null */
    private $expires_at;

    /** @var string|null */
    private $reminder_sent_at;

    /** @var string */
    private $joined_at;

    /** @var string */
    private $updated_at;

    public function __construct(
        int $user_id,
        int $balance = 0,
        int $pending_points = 0,
        int $lifetime_earned = 0,
        int $lifetime_redeemed = 0,
        ?string $last_activity_at = null,
        ?string $expires_at = null,
        ?string $reminder_sent_at = null,
        string $joined_at = '',
        string $updated_at = ''
    ) {
        $this->user_id           = $user_id;
        $this->balance           = $balance;
        $this->pending_points    = $pending_points;
        $this->lifetime_earned   = $lifetime_earned;
        $this->lifetime_redeemed = $lifetime_redeemed;
        $this->last_activity_at  = $last_activity_at;
        $this->expires_at        = $expires_at;
        $this->reminder_sent_at  = $reminder_sent_at;
        $this->joined_at         = $joined_at;
        $this->updated_at        = $updated_at;
    }

    public static function from_row( array $row ): self {
        return new self(
            self::cast_int_default( $row['user_id'] ?? 0 ),
            self::cast_int_default( $row['balance'] ?? 0 ),
            self::cast_int_default( $row['pending_points'] ?? 0 ),
            self::cast_int_default( $row['lifetime_earned'] ?? 0 ),
            self::cast_int_default( $row['lifetime_redeemed'] ?? 0 ),
            self::cast_datetime( $row['last_activity_at'] ?? null ),
            self::cast_datetime( $row['expires_at'] ?? null ),
            self::cast_datetime( $row['reminder_sent_at'] ?? null ),
            (string) ( $row['joined_at'] ?? '' ),
            (string) ( $row['updated_at'] ?? '' )
        );
    }

    public function to_row(): array {
        return [
            'user_id'           => $this->user_id,
            'balance'           => $this->balance,
            'pending_points'    => $this->pending_points,
            'lifetime_earned'   => $this->lifetime_earned,
            'lifetime_redeemed' => $this->lifetime_redeemed,
            'last_activity_at'  => $this->last_activity_at,
            'expires_at'        => $this->expires_at,
            'reminder_sent_at'  => $this->reminder_sent_at,
            'joined_at'         => $this->joined_at,
            'updated_at'        => $this->updated_at,
        ];
    }

    public function get_format(): array {
        // This model maps no decimal columns yet; the table already has
        // lifetime_spend/tier_progress_value and they must stay '%s'.
        return [ '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' ];
    }

    public function to_array(): array {
        return [
            'user_id'           => $this->user_id,
            'balance'           => $this->balance,
            'pending_points'    => $this->pending_points,
            'lifetime_earned'   => $this->lifetime_earned,
            'lifetime_redeemed' => $this->lifetime_redeemed,
            'last_activity_at'  => $this->last_activity_at,
            'expires_at'        => $this->expires_at,
            'joined_at'         => $this->joined_at,
            'updated_at'        => $this->updated_at,
        ];
    }

    public function get_user_id(): int {
        return $this->user_id;
    }

    public function get_balance(): int {
        return $this->balance;
    }

    public function get_pending_points(): int {
        return $this->pending_points;
    }

    public function get_lifetime_earned(): int {
        return $this->lifetime_earned;
    }

    public function get_lifetime_redeemed(): int {
        return $this->lifetime_redeemed;
    }

    public function get_last_activity_at(): ?string {
        return $this->last_activity_at;
    }

    public function get_expires_at(): ?string {
        return $this->expires_at;
    }

    public function get_joined_at(): string {
        return $this->joined_at;
    }
}
