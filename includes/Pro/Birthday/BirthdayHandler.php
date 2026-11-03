<?php
namespace YayLoyalty\Pro\Birthday;

use YayLoyalty\Engine\Points\Breakdown;
use YayLoyalty\Engine\Points\Context;
use YayLoyalty\Engine\Points\Triggers\TriggerHandler;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Models\Campaign;

defined( 'ABSPATH' ) || exit;

/**
 * Flat points once per birthday. The reference is the birthday's year, so the ledger's idempotency key
 * pays each customer once a year however often their birthday is edited or the run repeats.
 */
final class BirthdayHandler implements TriggerHandler {

    /** Context extra: the year of the birthday being paid, from BirthdayWindow::next(). */
    public const EXTRA_YEAR = 'birthday_year';

    /** Context extra: days from the site's today to that birthday. */
    public const EXTRA_DAYS_UNTIL = 'birthday_days_until';

    public function trigger(): string {
        return CampaignTrigger::BIRTHDAY;
    }

    /** Within this campaign's own early window; the run selects with the widest window of all live campaigns. */
    public function is_eligible( Campaign $campaign, Context $ctx ): bool {
        $days_until = $ctx->get_extra( self::EXTRA_DAYS_UNTIL );

        return null !== $days_until && (int) $days_until <= self::days_before( $campaign );
    }

    /** How many days before the birthday $campaign pays; 0 pays on the day. */
    public static function days_before( Campaign $campaign ): int {
        return (int) ( $campaign->get_config()['days_before'] ?? 0 );
    }

    public function compute_points( Campaign $campaign, Context $ctx ): Breakdown {
        return Breakdown::flat( $campaign->flat_points() );
    }

    public function advertised_points( Campaign $campaign ): float {
        return (float) $campaign->flat_points();
    }

    public function reference_type(): string {
        return ReferenceType::BIRTHDAY;
    }

    public function reference_id( Context $ctx ): ?int {
        $year = $ctx->get_extra( self::EXTRA_YEAR );

        return null !== $year ? (int) $year : null;
    }

    public function source(): string {
        return LedgerSource::BIRTHDAY;
    }
}
