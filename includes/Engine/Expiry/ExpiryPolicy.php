<?php
namespace YayLoyalty\Engine\Expiry;

use YayLoyalty\Enums\ExpiryMode;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Models\Customer;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * The effective expiry rules, read from the `expiry` settings group, and the date maths behind them
 * (static and pure, on UTC `Y-m-d H:i:s` strings). A stored mode this build cannot
 * run (batch without Pro) falls back to inactivity, and only batch honours the spend order.
 */
final class ExpiryPolicy {

    public const SPEND_OLDEST = 'oldest';

    public const SPEND_NEWEST = 'newest';

    /** Shortening the window keeps every stored date; the shorter window applies from each customer's next activity. */
    public const ON_SHORTEN_KEEP = 'keep';

    /** Shortening the window moves stored dates to the new window, with no grace: a date already past expires on the next run. */
    public const ON_SHORTEN_APPLY = 'apply';

    /**
     * Turning expiry on, or shortening it while keeping dates, never sets a date sooner than this many days from now,
     * or than the window itself when it is shorter (grace_days()).
     */
    public const GRACE_DAYS = 30;

    public static function inactivity_expires_at( string $base_utc, int $days ): string {
        return Dates::offset( $days * 86400, $base_utc );
    }

    /** $months after the earned date, on the same day of the month or the month's last day: 31 Jan + 1 = 28/29 Feb. */
    public static function batch_expires_at( string $earned_utc, int $months ): string {
        $earned = new \DateTimeImmutable( $earned_utc, new \DateTimeZone( 'UTC' ) );
        $first  = $earned->modify( 'first day of this month' )->modify( '+' . $months . ' months' );
        $day    = min( (int) $earned->format( 'j' ), (int) $first->format( 't' ) );

        return $first->setDate( (int) $first->format( 'Y' ), (int) $first->format( 'n' ), $day )->format( Dates::MYSQL_FORMAT );
    }

    /** The later of the two. Same-format UTC strings compare in date order. */
    public static function floor_at( string $expires_at, string $floor ): string {
        return strcmp( $expires_at, $floor ) >= 0 ? $expires_at : $floor;
    }

    /** @var array<string, mixed> */
    private $settings;

    /** @param array<string, mixed> $settings The `expiry` settings group. */
    public function __construct( array $settings ) {
        $this->settings = $settings;
    }

    public static function current(): self {
        return new self( SettingsHelper::get_settings()['expiry'] );
    }

    public function is_enabled(): bool {
        return ! empty( $this->settings['enabled'] );
    }

    public function mode(): string {
        $mode = $this->settings['mode'] ?? '';

        return ExpiryMode::is_valid( $mode ) ? $mode : ExpiryMode::INACTIVITY;
    }

    public function inactivity_days(): int {
        return max( 1, (int) $this->settings['inactivity_days'] );
    }

    public function batch_months(): int {
        return max( 1, (int) $this->settings['batch_months'] );
    }

    /**
     * Days before expiry the Points expiring soon email goes out: always fewer than the window, even for a
     * stored pair that SettingsHelper::is_valid() would refuse, or every activity would trigger a reminder.
     */
    public function reminder_days(): int {
        return max( 1, min( $this->stored_reminder_days(), $this->window_days() - 1 ) );
    }

    /** The stored reminder timing, unclamped, for validating a save. */
    public function stored_reminder_days(): int {
        return (int) $this->settings['reminder_days'];
    }

    /**
     * The least time a newly set date gives anyone: GRACE_DAYS, or the window when that is shorter, so points
     * an admin adds never outlast what the customer would have had for earning them. LocalizeHelper passes
     * GRACE_DAYS to the admin, whose confirmation applies the same cap (expiry-settings.form.ts).
     */
    public function grace_days(): int {
        return min( self::GRACE_DAYS, $this->window_days() );
    }

    /** Now + grace_days(), the earliest date a settings change, a lot backfill or an adjust may give anyone. */
    public function grace_floor( string $now ): string {
        return self::inactivity_expires_at( $now, $this->grace_days() );
    }

    /** The window the effective mode runs on, in days: a month counts as its shortest, 28 days. */
    public function window_days(): int {
        return ExpiryMode::BATCH === $this->mode() ? $this->batch_months() * 28 : $this->inactivity_days();
    }

    /** Whether the stored customer and lot dates were computed from these settings. */
    public function is_applied(): bool {
        return get_option( ExpiryRunner::OPTION_APPLIED ) === $this->signature();
    }

    /** Whether shortening the window keeps the dates already stored, as the admin chose when saving it. */
    public function keeps_dates(): bool {
        return self::ON_SHORTEN_APPLY !== ( $this->settings['on_shorten'] ?? '' );
    }

    public function spend_order(): string {
        $is_newest = $this->is_batch() && self::SPEND_NEWEST === ( $this->settings['spend_order'] ?? '' );

        return $is_newest ? self::SPEND_NEWEST : self::SPEND_OLDEST;
    }

    /** What the stored customer and lot dates were computed from: a change means they must be recomputed. */
    public function signature(): string {
        $window = ExpiryMode::BATCH === $this->mode() ? $this->batch_months() : $this->inactivity_days();

        return implode( '|', [ (int) $this->is_enabled(), $this->mode(), $window ] );
    }

    /**
     * Whether an expiry may run for the locked customer. The stored dates must have been computed from
     * these settings: after a save they wait for the job's restamp. In inactivity mode the customer's
     * date must have passed; in batch mode the caller asks which lots are due.
     */
    public function is_due( Customer $customer, string $now ): bool {
        if ( ! $this->is_enabled() || ! $this->is_applied() ) {
            return false;
        }

        $expires_at = $customer->get_expires_at();

        return ExpiryMode::BATCH === $this->mode() || ( null !== $expires_at && strcmp( $expires_at, $now ) <= 0 );
    }

    public function is_batch(): bool {
        return $this->is_enabled() && ExpiryMode::BATCH === $this->mode();
    }

    /** A new lot's expiry: set only while batch expiry is on. */
    public function lot_expires_at( string $earned_at ): ?string {
        return $this->is_batch() ? self::batch_expires_at( $earned_at, $this->batch_months() ) : null;
    }

    /**
     * customers.expires_at after $row. Only earn and redeem reset the inactivity clock, and never move a
     * stored date sooner: a date kept when the window was shortened stands until activity passes it. Any
     * other row (a refund, an adjust) keeps a stored date that is still ahead, so it neither shortens nor
     * extends the customer's time. Without one, or with one already past, it lands on last activity + the
     * window, never sooner than the grace floor, or a positive adjust for a dormant customer would be due at once.
     *
     * @param Customer    $before            The locked row before $row.
     * @param string|null $earliest_open_lot The soonest open lot expiry after $row, for batch mode.
     */
    public function customer_expires_at( LedgerEntry $row, Customer $before, ?string $earliest_open_lot, string $now ): ?string {
        if ( ! $this->is_enabled() ) {
            return $before->get_expires_at();
        }

        if ( $before->get_balance() + $row->get_points() <= 0 ) {
            return null;
        }

        if ( ExpiryMode::BATCH === $this->mode() ) {
            return $earliest_open_lot;
        }

        $kept = $before->get_expires_at();

        if ( LedgerType::is_activity( $row->get_type() ) ) {
            $expires_at = self::inactivity_expires_at( $row->get_created_at(), $this->inactivity_days() );

            return null === $kept ? $expires_at : self::floor_at( $expires_at, $kept );
        }

        if ( null !== $kept && strcmp( $kept, $now ) > 0 ) {
            return $kept;
        }

        $base = $before->get_last_activity_at() ?? $before->get_joined_at();

        return self::floor_at( self::inactivity_expires_at( $base, $this->inactivity_days() ), $this->grace_floor( $now ) );
    }
}
