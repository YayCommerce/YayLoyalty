<?php
namespace YayLoyalty\Engine\Expiry;

use YayLoyalty\Enums\ExpiryMode;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Models\Customer;
use YayLoyalty\Models\LedgerEntry;

defined( 'ABSPATH' ) || exit;

/**
 * The effective expiry rules, read from the `expiry` settings group. A stored mode this build cannot
 * run (batch without Pro) falls back to inactivity, and only batch honours the spend order.
 */
final class ExpiryPolicy {

    public const SPEND_OLDEST = 'oldest';

    public const SPEND_NEWEST = 'newest';

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
        $mode = (string) ( $this->settings['mode'] ?? '' );

        return in_array( $mode, ExpiryMode::all(), true ) ? $mode : ExpiryMode::INACTIVITY;
    }

    public function inactivity_days(): int {
        return max( 1, (int) ( $this->settings['inactivity_days'] ?? 365 ) );
    }

    public function batch_months(): int {
        return max( 1, (int) ( $this->settings['batch_months'] ?? 12 ) );
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
        if ( ! $this->is_enabled() || get_option( ExpiryRunner::OPTION_APPLIED ) !== $this->signature() ) {
            return false;
        }

        $expires_at = $customer->get_expires_at();

        return ExpiryMode::BATCH === $this->mode() || ( null !== $expires_at && strcmp( $expires_at, $now ) <= 0 );
    }

    public function is_batch(): bool {
        return $this->is_enabled() && ExpiryMode::BATCH === $this->mode();
    }

    /** A new lot's expiry: set only while batch expiry is on. */
    public function lot_expires_at( string $created_at ): ?string {
        return $this->is_batch() ? ExpiryDates::batch_expires_at( $created_at, $this->batch_months() ) : null;
    }

    /**
     * customers.expires_at after $row. Only earn and redeem reset the inactivity clock. Any other row
     * keeps the clock but never lands sooner than the grace floor, or a positive adjust for a dormant
     * customer would be due at once.
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

        if ( LedgerType::is_activity( $row->get_type() ) ) {
            return ExpiryDates::inactivity_expires_at( $row->get_created_at(), $this->inactivity_days() );
        }

        $base = $before->get_last_activity_at() ?? $before->get_joined_at();

        return ExpiryDates::floor_at( ExpiryDates::inactivity_expires_at( $base, $this->inactivity_days() ), ExpiryDates::grace_floor( $now ) );
    }
}
