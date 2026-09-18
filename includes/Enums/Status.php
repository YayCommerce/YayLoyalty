<?php
namespace YayLoyalty\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * Merchant intent for a definition row. Status is intent, liveness is
 * derived: `scheduled` and `ended` are never stored, only computed from the
 * date window against the event's timestamp, so a late-processed order is
 * scored under the rules live when it happened. No cron may flip a status.
 */
final class Status {

    /** Live, subject to its date window. */
    public const ACTIVE = 'active';

    /** Temporarily off, dates ignored. */
    public const PAUSED = 'paused';

    /** Never live; not finished being configured. */
    public const DRAFT = 'draft';

    /** Retired by the merchant. Kept for history; not offered for editing. */
    public const ARCHIVED = 'archived';

    /** Derived display states, never stored; excluded from all(). */
    public const SCHEDULED = 'scheduled';
    public const ENDED     = 'ended';

    /**
     * The stored vocabulary.
     *
     * @return string[]
     */
    public static function all(): array {
        return [
            self::ACTIVE,
            self::PAUSED,
            self::DRAFT,
            self::ARCHIVED,
        ];
    }

    public static function is_valid( $value ): bool {
        return is_string( $value ) && in_array( $value, self::all(), true );
    }

    /**
     * @throws \InvalidArgumentException When $value is not a known status.
     */
    public static function assert( $value ): string {
        if ( ! self::is_valid( $value ) ) {
            throw new \InvalidArgumentException(
                sprintf(
                    'YayLoyalty: unknown status "%s". Expected one of: %s. '
                    . 'Note that "scheduled" and "ended" are not statuses — liveness is derived from the dates.',
                    is_scalar( $value ) ? (string) $value : gettype( $value ),
                    implode( ', ', self::all() )
                )
            );
        }

        return $value;
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
}
