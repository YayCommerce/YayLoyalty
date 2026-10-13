<?php
namespace YayLoyalty\Services;

use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Models\Customer;
use YayLoyalty\Repositories\LotRepository;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * The one place that turns the `expiry` settings into dates. LotWriter keeps lots and customers.expires_at
 * by these rules row by row; Engine\Expiry\ExpiryRecompute applies the same rules to every row in SQL.
 */
final class ExpiryRules {

    /** The whole balance expires after a period with no earning or redeeming. */
    public const MODEL_INACTIVITY = 'inactivity';

    /** Each lot expires a set number of months after it was earned. Only Pro's filter selects it. */
    public const MODEL_BATCHES = 'batches';

    /** @var string[] Every model a setting may store, whichever this build acts on. */
    public const MODELS = [ self::MODEL_INACTIVITY, self::MODEL_BATCHES ];

    public const MODEL_FILTER = 'yayloyalty_expiry_model';

    /** Filters the reminder stages, stage => window in days. Pro adds `final`. */
    public const STAGES_FILTER = 'yayloyalty_expiry_reminder_stages';

    /** Filters the stage one customer gets; '' skips them. Pro applies its reminder floor here. */
    public const REMINDER_FILTER = 'yayloyalty_expiry_reminder';

    /** The free reminder stage, sent `reminder_days` before the date. */
    public const STAGE_SOON = 'soon';

    /** The last-chance stage, sent `final_reminder_days` before the date. Only Pro adds it to the stages. */
    public const STAGE_FINAL = 'final';

    public static function enabled(): bool {
        return (bool) self::settings()['enabled'];
    }

    public static function inactivity_days(): int {
        return (int) self::settings()['inactivity_days'];
    }

    public static function batch_months(): int {
        return (int) self::settings()['batch_months'];
    }

    public static function reminder_days(): int {
        return (int) self::settings()['reminder_days'];
    }

    /**
     * Reminder stages in force, narrowest window first, each window above 0 days.
     *
     * @return array<string, int> Stage => days before expiry.
     */
    public static function reminder_stages(): array {
        /**
         * Reminder stages, stage => days before the expiry date. A stage's email listens on
         * Emails::reminder_action( stage ). A window of 0 or less is dropped.
         *
         * @param array<string, int> $stages `soon` => the reminder_days setting.
         */
        $stages = (array) apply_filters( self::STAGES_FILTER, [ self::STAGE_SOON => self::reminder_days() ] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant holds the prefixed name; the sniff cannot read it.
        $stages = array_filter( array_map( 'intval', $stages ), static fn( int $days ): bool => $days > 0 );

        asort( $stages );

        return $stages;
    }

    /** The model in force. Lite always answers inactivity: the stored model acts only through Pro's filter. */
    public static function model(): string {
        /**
         * The expiry model in force. Pro answers the stored `expiry.model`; anything but batches reads as inactivity.
         *
         * @param string $model ExpiryRules::MODEL_INACTIVITY.
         */
        $model = apply_filters( self::MODEL_FILTER, self::MODEL_INACTIVITY ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant holds the prefixed name; the sniff cannot read it.

        return self::MODEL_BATCHES === $model ? self::MODEL_BATCHES : self::MODEL_INACTIVITY;
    }

    /** When a lot opened at $created_at expires: never, unless expiry is on under the batch model. */
    public static function lot_expires_at( string $created_at ): ?string {
        if ( ! self::enabled() || self::MODEL_BATCHES !== self::model() ) {
            return null;
        }

        return Dates::add_months( $created_at, self::batch_months() );
    }

    /**
     * When the customer's next points expire, for customers.expires_at. Null when expiry is off or nothing
     * is held. Without any earn or redeem, inactivity counts from joining, so granted points still expire.
     */
    public static function customer_expires_at( int $balance, ?string $last_activity_at, string $joined_at, ?string $earliest_lot_expiry ): ?string {
        if ( ! self::enabled() || $balance <= 0 ) {
            return null;
        }

        if ( self::MODEL_BATCHES === self::model() ) {
            return $earliest_lot_expiry;
        }

        return Dates::offset( self::inactivity_days() * DAY_IN_SECONDS, $last_activity_at ?? $joined_at );
    }

    /** What would expire by $at: the whole positive balance under inactivity, the due lots under batches. */
    public static function expiring_points( Customer $customer, string $at ): int {
        if ( self::MODEL_BATCHES === self::model() ) {
            return ( new LotRepository() )->sum_due( $customer->get_user_id(), $at );
        }

        return max( $customer->get_balance(), 0 );
    }

    /** @return array<string, mixed> */
    private static function settings(): array {
        return SettingsHelper::get_settings()['expiry'];
    }
}
