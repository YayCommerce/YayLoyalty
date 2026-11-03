<?php
namespace YayLoyalty\Engine\Points\Triggers;

use YayLoyalty\Engine\Points\Breakdown;
use YayLoyalty\Engine\Points\Context;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Models\Campaign;

defined( 'ABSPATH' ) || exit;

/** Flat points for registering. No base, no gates. */
final class SignupHandler implements TriggerHandler {

    public function trigger(): string {
        return CampaignTrigger::SIGNUP;
    }

    public function is_eligible( Campaign $campaign, Context $ctx ): bool {
        return true;
    }

    public function compute_points( Campaign $campaign, Context $ctx ): Breakdown {
        return Breakdown::flat( $campaign->flat_points() );
    }

    public function advertised_points( Campaign $campaign ): float {
        return (float) $campaign->flat_points();
    }

    public function reference_type(): string {
        return ReferenceType::USER;
    }

    public function reference_id( Context $ctx ): ?int {
        return $ctx->get_user_id();
    }

    public function source(): string {
        return LedgerSource::SIGNUP;
    }
}
