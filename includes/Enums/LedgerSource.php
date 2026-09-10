<?php
namespace YayLoyalty\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * Why a balance moved.
 *
 * Distinct from LedgerType, which says what kind of movement it was. Source
 * lets history and analytics group rows without decoding reference_type /
 * reference_id.
 *
 * See LedgerType for why assert() belongs at the service boundary.
 */
final class LedgerSource {

    public const ORDER     = 'order';
    public const SIGNUP    = 'signup';
    public const REVIEW    = 'review';
    public const REFERRAL  = 'referral';
    public const BIRTHDAY  = 'birthday';
    public const MILESTONE = 'milestone';
    public const SOCIAL    = 'social';
    public const RENEWAL   = 'renewal';
    public const MANUAL    = 'manual';
    public const IMPORT    = 'import';
    public const EXPIRY    = 'expiry';
    public const SYSTEM    = 'system';

    /**
     * @return string[]
     */
    public static function all(): array {
        return [
            self::ORDER,
            self::SIGNUP,
            self::REVIEW,
            self::REFERRAL,
            self::BIRTHDAY,
            self::MILESTONE,
            self::SOCIAL,
            self::RENEWAL,
            self::MANUAL,
            self::IMPORT,
            self::EXPIRY,
            self::SYSTEM,
        ];
    }

    public static function is_valid( $value ): bool {
        return is_string( $value ) && in_array( $value, self::all(), true );
    }

    /**
     * @throws \InvalidArgumentException When $value is not a known source.
     */
    public static function assert( $value ): string {
        if ( ! self::is_valid( $value ) ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'YayLoyalty: unknown ledger source "%s". Expected one of: %s.',
                    is_scalar( $value ) ? (string) $value : gettype( $value ),
                    implode( ', ', self::all() )
                )
            );
        }

        return $value;
    }
}
