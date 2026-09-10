<?php
namespace YayLoyalty\Enums;

defined( 'ABSPATH' ) || exit;

/**
 * Merchant intent for a definition row (campaign, reward, tier).
 *
 * STATUS IS INTENT; LIVENESS IS DERIVED.
 *
 * There is deliberately no `scheduled` and no `ended`. Whether something is live
 * right now is computed at read time from status plus starts_at/ends_at, and is
 * evaluated against the EVENT's timestamp — an order's award time, not the
 * current request time — so a late-processed order is scored under the rules
 * that were live when it happened.
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

    /**
     * @return string[]
     */
    public static function all(): array {
        return [
            self::ACTIVE,
            self::PAUSED,
            self::DRAFT,
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
}
