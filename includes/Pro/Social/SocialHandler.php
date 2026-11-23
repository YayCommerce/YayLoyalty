<?php
namespace YayLoyalty\Pro\Social;

use YayLoyalty\Engine\Points\Breakdown;
use YayLoyalty\Engine\Points\Context;
use YayLoyalty\Engine\Points\Triggers\TriggerHandler;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Models\Campaign;

defined( 'ABSPATH' ) || exit;

/**
 * Flat points for one social action, once per customer per campaign: the reference is the campaign,
 * so the ledger's idempotency key refuses a second payment.
 */
final class SocialHandler implements TriggerHandler {

    /** Context extra: the id of the social campaign the customer clicked. */
    public const EXTRA_CAMPAIGN_ID = 'social_campaign_id';

    /** Network key => its name, shown as is in every language. */
    public const NETWORKS = [
        'facebook'  => 'Facebook',
        'instagram' => 'Instagram',
        'x'         => 'X',
        'tiktok'    => 'TikTok',
        'youtube'   => 'YouTube',
        'linkedin'  => 'LinkedIn',
        'pinterest' => 'Pinterest',
    ];

    public const ACTIONS = [ 'follow', 'share' ];

    public function trigger(): string {
        return CampaignTrigger::SOCIAL;
    }

    public function is_eligible( Campaign $campaign, Context $ctx ): bool {
        return null !== $campaign->get_id() && (int) $ctx->get_extra( self::EXTRA_CAMPAIGN_ID ) === $campaign->get_id();
    }

    public function compute_points( Campaign $campaign, Context $ctx ): Breakdown {
        return Breakdown::flat( $campaign->flat_points() );
    }

    public function advertised_points( Campaign $campaign ): float {
        return (float) $campaign->flat_points();
    }

    public function reference_type(): string {
        return ReferenceType::SOCIAL;
    }

    public function reference_id( Context $ctx ): ?int {
        $campaign_id = $ctx->get_extra( self::EXTRA_CAMPAIGN_ID );

        return null !== $campaign_id ? (int) $campaign_id : null;
    }

    public function source(): string {
        return LedgerSource::SOCIAL;
    }
}
