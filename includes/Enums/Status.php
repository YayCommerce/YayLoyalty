<?php
namespace YayLoyalty\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * Merchant intent for a definition row. Liveness is derived: `scheduled` and `ended` are never stored.
 * They are computed from the date window at the event's timestamp, so a late-processed order
 * is scored under the rules live when it happened. No cron may flip a status.
 */
final class Status {

    use StringEnumMethods;

    /** Live, subject to its date window. */
    public const ACTIVE = 'active';

    /** Temporarily off, dates ignored. */
    public const PAUSED = 'paused';

    /** Never live: not yet fully configured. */
    public const DRAFT = 'draft';

    /** Retired by the merchant. Kept for history, not offered for editing. */
    public const ARCHIVED = 'archived';

    /** Derived display states, never stored, so all() leaves them out. */
    public const SCHEDULED = 'scheduled';
    public const ENDED     = 'ended';

    /** @return string[] The stored vocabulary. */
    public static function all(): array {
        return [
            self::ACTIVE,
            self::PAUSED,
            self::DRAFT,
            self::ARCHIVED,
        ];
    }

    /**
     * Whether a row is live at $at. ends_at is exclusive, so back-to-back windows never overlap.
     *
     * @param string|null $starts_at UTC MySQL datetime, or null for no lower bound.
     * @param string|null $ends_at   UTC MySQL datetime, or null for no upper bound.
     * @param string      $at        UTC MySQL datetime of the event being scored.
     */
    public static function is_live_at( string $status, ?string $starts_at, ?string $ends_at, string $at ): bool {
        if ( self::ACTIVE !== $status ) {
            return false;
        }

        if ( null !== $starts_at && '' !== $starts_at && $starts_at > $at ) {
            return false;
        }

        if ( null !== $ends_at && '' !== $ends_at && $ends_at <= $at ) {
            return false;
        }

        return true;
    }

    /**
     * The label to show: the stored status, or `scheduled` / `ended` derived
     * from the date window when it is `active`.
     *
     * @param string|null $starts_at UTC MySQL datetime, or null for no lower bound.
     * @param string|null $ends_at   UTC MySQL datetime, or null for no upper bound.
     * @param string      $at        UTC MySQL datetime to evaluate against.
     */
    public static function resolve_display( string $status, ?string $starts_at, ?string $ends_at, string $at ): string {
        if ( self::ACTIVE !== $status ) {
            return $status;
        }

        if ( null !== $starts_at && '' !== $starts_at && $starts_at > $at ) {
            return self::SCHEDULED;
        }

        if ( null !== $ends_at && '' !== $ends_at && $ends_at <= $at ) {
            return self::ENDED;
        }

        return self::ACTIVE;
    }

    protected static function noun(): string {
        return 'status';
    }

    protected static function assert_note(): string {
        return ' Note that "scheduled" and "ended" are not statuses — liveness is derived from the dates.';
    }
}
