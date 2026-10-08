<?php
namespace YayLoyalty\Engine\Points\Triggers;

use YayLoyalty\Engine\Points\Breakdown;
use YayLoyalty\Engine\Points\Context;
use YayLoyalty\Models\Campaign;

defined( 'ABSPATH' ) || exit;

/**
 * One trigger's rules: eligibility and points. A config gate fails
 * is_eligible() so the engine falls through; compute_points() is reached
 * only for a campaign that passed and never throws.
 */
interface TriggerHandler {

    /** A CampaignTrigger constant. */
    public function trigger(): string;

    public function is_eligible( Campaign $campaign, Context $ctx ): bool;

    public function compute_points( Campaign $campaign, Context $ctx ): Breakdown;

    /**
     * What a campaign pays with no event, for the storefront to advertise: points per currency unit or flat
     * points; 0 pays nothing. Required: a handler registered without it is a fatal error.
     */
    public function advertised_value( Campaign $campaign ): float;

    /** A ReferenceType constant. */
    public function reference_type(): string;

    public function reference_id( Context $ctx ): ?int;

    /** A LedgerSource constant. */
    public function source(): string;
}
