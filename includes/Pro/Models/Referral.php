<?php
namespace YayLoyalty\Pro\Models;

use YayLoyalty\Models\AbstractModel;
use YayLoyalty\Pro\Enums\ReferralStatus;
use YayLoyalty\Utils\Json;

defined( 'ABSPATH' ) || exit;

/**
 * One referral: an advocate, the friend they brought in, and the terms in force when the friend signed up.
 * The terms are a snapshot, so a later settings change never alters a referral already made.
 */
final class Referral extends AbstractModel {

    /** `schema_version` of the stored `terms` JSON. */
    public const TERMS_VERSION = 1;

    private ?int $id;

    private int $advocate_user_id;

    private int $friend_user_id;

    /** The code the friend arrived with, kept even if the advocate's code changes later. */
    private string $referral_code;

    private string $status;

    private ?string $reject_reason;

    /** @var array<string, mixed> */
    private array $terms;

    private ?string $coupon_expires_at;

    private ?int $first_order_id;

    private ?string $hold_until;

    private string $created_at;

    private string $updated_at;

    /**
     * @param array<string, mixed> $terms
     */
    public function __construct(
        ?int $id,
        int $advocate_user_id,
        int $friend_user_id,
        string $referral_code,
        string $status,
        ?string $reject_reason,
        array $terms,
        ?string $coupon_expires_at,
        ?int $first_order_id,
        ?string $hold_until,
        string $created_at,
        string $updated_at
    ) {
        $this->id                = $id;
        $this->advocate_user_id  = $advocate_user_id;
        $this->friend_user_id    = $friend_user_id;
        $this->referral_code     = $referral_code;
        $this->status            = $status;
        $this->reject_reason     = $reject_reason;
        $this->terms             = $terms;
        $this->coupon_expires_at = $coupon_expires_at;
        $this->first_order_id    = $first_order_id;
        $this->hold_until        = $hold_until;
        $this->created_at        = $created_at;
        $this->updated_at        = $updated_at;
    }

    public function get_id(): ?int {
        return $this->id;
    }

    public function get_advocate_user_id(): int {
        return $this->advocate_user_id;
    }

    public function get_friend_user_id(): int {
        return $this->friend_user_id;
    }

    public function get_status(): string {
        return $this->status;
    }

    /** @return array<string, mixed> */
    public function get_terms(): array {
        return $this->terms;
    }

    public function get_coupon_expires_at(): ?string {
        return $this->coupon_expires_at;
    }

    public function get_hold_until(): ?string {
        return $this->hold_until;
    }

    public function get_first_order_id(): ?int {
        return $this->first_order_id;
    }

    public static function from_row( array $row ): self {
        return new self(
            self::cast_int( $row['id'] ?? null ),
            self::cast_int_default( $row['advocate_user_id'] ?? 0 ),
            self::cast_int_default( $row['friend_user_id'] ?? 0 ),
            (string) ( $row['referral_code'] ?? '' ),
            (string) ( $row['status'] ?? ReferralStatus::PENDING ),
            self::cast_string( $row['reject_reason'] ?? null ),
            Json::decode( isset( $row['terms'] ) ? (string) $row['terms'] : null ),
            self::cast_datetime( $row['coupon_expires_at'] ?? null ),
            self::cast_int( $row['first_order_id'] ?? null ),
            self::cast_datetime( $row['hold_until'] ?? null ),
            (string) ( $row['created_at'] ?? '' ),
            (string) ( $row['updated_at'] ?? '' )
        );
    }

    /** Insert payload. `id` is omitted so AUTO_INCREMENT assigns it. */
    public function to_row(): array {
        return [
            'advocate_user_id'  => $this->advocate_user_id,
            'friend_user_id'    => $this->friend_user_id,
            'referral_code'     => $this->referral_code,
            'status'            => $this->status,
            'reject_reason'     => $this->reject_reason,
            'terms'             => Json::encode( $this->terms, self::TERMS_VERSION ),
            'coupon_expires_at' => $this->coupon_expires_at,
            'first_order_id'    => $this->first_order_id,
            'hold_until'        => $this->hold_until,
            'created_at'        => $this->created_at,
            'updated_at'        => $this->updated_at,
        ];
    }

    public function get_format(): array {
        return [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ];
    }

    public function to_array(): array {
        $row          = $this->to_row();
        $row['terms'] = $this->terms;

        return array_merge( [ 'id' => $this->id ], $row );
    }
}
