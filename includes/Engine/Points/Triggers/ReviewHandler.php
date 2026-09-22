<?php
namespace YayLoyalty\Engine\Points\Triggers;

use YayLoyalty\Engine\Points\Breakdown;
use YayLoyalty\Engine\Points\Context;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Models\Campaign;

defined( 'ABSPATH' ) || exit;

/** Flat points for an approved review. Decides require_verified only; daily_cap is CapPolicy's, one_per_product an Engine\Earning\Gate's. */
final class ReviewHandler implements TriggerHandler {

    /** Context extra the caller sets: whether the reviewer bought the product. */
    public const EXTRA_VERIFIED_PURCHASE = 'verified_purchase';

    public function trigger(): string {
        return CampaignTrigger::REVIEW;
    }

    public function is_eligible( Campaign $campaign, Context $ctx ): bool {
        if ( empty( $campaign->get_config()['require_verified'] ) ) {
            return true;
        }

        // Absent is not verified.
        return (bool) $ctx->get_extra( self::EXTRA_VERIFIED_PURCHASE, false );
    }

    public function compute_points( Campaign $campaign, Context $ctx ): Breakdown {
        return Breakdown::flat( (int) ( $campaign->get_config()['points'] ?? 0 ) );
    }

    public function reference_type(): string {
        return ReferenceType::COMMENT;
    }

    public function reference_id( Context $ctx ): ?int {
        return $ctx->get_comment_id();
    }

    public function source(): string {
        return LedgerSource::REVIEW;
    }
}
