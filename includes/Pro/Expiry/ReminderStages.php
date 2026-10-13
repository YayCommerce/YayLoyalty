<?php
namespace YayLoyalty\Pro\Expiry;

use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Models\Customer;
use YayLoyalty\Services\ExpiryRules;

defined( 'ABSPATH' ) || exit;

/**
 * Pro's expiry reminders: a second, `final` stage closer to the date, and a floor below which neither
 * reminder goes out. Attached to the expiry job's reminder filters by YayLoyaltyPro::boot().
 */
final class ReminderStages {

    /**
     * Adds the final stage at `expiry.final_reminder_days`. ExpiryRules drops a window of 0 (off).
     *
     * @param array<string, int> $stages
     * @return array<string, int>
     */
    public static function add_final( array $stages ): array {
        $stages[ ExpiryRules::STAGE_FINAL ] = (int) SettingsHelper::get_settings()['expiry']['final_reminder_days'];

        return $stages;
    }

    /** Skips a reminder about fewer points than `expiry.reminder_min_points`; null reminds whatever the amount. */
    public static function apply_floor( string $stage, Customer $customer, int $days_left, int $expiring_points ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- the filter's signature.
        $floor = SettingsHelper::get_settings()['expiry']['reminder_min_points'];

        return null !== $floor && $expiring_points < (int) $floor ? '' : $stage;
    }
}
