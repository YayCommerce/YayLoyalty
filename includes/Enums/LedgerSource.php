<?php
namespace YayLoyalty\Enums;

defined( 'ABSPATH' ) || exit;

/** Why a balance moved; LedgerType says what kind of movement it was. */
final class LedgerSource {

    use StringEnumMethods;

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

    /** @return string[] */
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

    protected static function noun(): string {
        return 'ledger source';
    }
}
