<?php
namespace YayLoyalty\Engine\Points\Triggers;

use YayLoyalty\Engine\Points\Breakdown;
use YayLoyalty\Engine\Points\Context;
use YayLoyalty\Models\Campaign;

defined( 'ABSPATH' ) || exit;

/**
 * One trigger's rules: is this campaign eligible for this event, and how
 * many points does it pay.
 *
 * ELIGIBILITY IS NOT CALCULATION. A config gate (min_subtotal, a paused
 * default for a multiplier) fails is_eligible() so the engine falls through
 * to the next campaign; compute_points() is only ever reached for a campaign
 * that already passed, so it has nothing left to fail on and never throws.
 */
interface TriggerHandler {

    /** A CampaignTrigger constant. */
    public function trigger(): string;

    public function is_eligible( Campaign $campaign, Context $ctx ): bool;

    public function compute_points( Campaign $campaign, Context $ctx ): Breakdown;

    /** A ReferenceType constant: what the ledger row's reference_id names. */
    public function reference_type(): string;

    public function reference_id( Context $ctx ): ?int;

    /** A LedgerSource constant. */
    public function source(): string;
}
