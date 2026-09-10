<?php
namespace YayLoyalty\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * Merchant intent for a definition row (campaign, reward, tier).
 *
 * STATUS IS INTENT; LIVENESS IS DERIVED.
 *
 * The stored vocabulary is active / paused / draft / archived. There is
 * deliberately no `scheduled` and no `ended` stored: whether something is live
 * right now is computed at read time from status plus starts_at/ends_at, and is
 * evaluated against the EVENT's timestamp — an order's award time, not the
 * current request time — so a late-processed order is scored under the rules
 * that were live when it happened. `scheduled` and `ended` exist only as the
 * derived answers returned by resolve_display().
 *
 * No cron job may flip a status. If one appears, the model is being worked
 * around.
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

    /**
     * Derived display states — NEVER stored. resolve_display() returns one of
     * these in place of `active` when the date window puts the row outside its
     * live period. Excluded from all() on purpose.
     */
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
     * Is a row live at a given instant?
     *
     * Shared by every definition table so the rule exists once.
     *
     * ends_at is EXCLUSIVE, so back-to-back windows never overlap.
     *
     * @param string      $status    Stored status.
     * @param string|null $starts_at UTC MySQL datetime, or null for no lower bound.
     * @param string|null $ends_at   UTC MySQL datetime, or null for no upper bound.
     * @param string      $at        UTC MySQL datetime of the EVENT being scored.
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
     * The label to show for a row: its stored status, or — when that status is
     * `active` — `scheduled` / `ended` derived from the date window.
     *
     * Stored statuses other than `active` (paused, draft, archived) are returned
     * unchanged: the date window only qualifies an active row.
     *
     * $at is the instant to evaluate against. For a display badge that is
     * "now"; for scoring a late order it is the order's award time.
     *
     * @param string      $status    Stored status.
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
