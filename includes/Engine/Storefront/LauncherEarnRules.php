<?php
namespace YayLoyalty\Engine\Storefront;

use YayLoyalty\Engine\Points\Context;
use YayLoyalty\Engine\Points\PointsEngine;
use YayLoyalty\Engine\Points\Triggers\PurchaseHandler;
use YayLoyalty\Enums\CampaignTrigger;

defined( 'ABSPATH' ) || exit;

/**
 * The "Ways to earn" rows the launcher lists. PointsEngine::advertised() decides each trigger's campaign
 * and value, through the same loop and stop rule that pays it; this class only shapes the rows.
 */
final class LauncherEarnRules {

    /** The triggers the launcher advertises, in display order: Lite's three. */
    public const TRIGGERS = [ CampaignTrigger::PURCHASE, CampaignTrigger::SIGNUP, CampaignTrigger::REVIEW ];

    /**
     * @param PointsEngine $engine       The engine that pays these triggers.
     * @param Context      $ctx          ContextFactory::for_advert() at the time the rows are for.
     * @param bool         $can_register Whether a shopper can create an account on this store. The signup
     *                                   campaign pays only a self-registration, so without one it isn't advertised.
     * @return array{earn: array{purchase: ?float, signup: ?int, review: ?int}, purchase_min: float}
     *         Points per currency unit / per signup / per review (null when not advertised), and the
     *         advertised purchase campaign's minimum order subtotal so the rate line can say it (0 for none).
     * @throws \InvalidArgumentException From PointsEngine::advertised().
     */
    public static function advertise( PointsEngine $engine, Context $ctx, bool $can_register ): array {
        $earn         = array_fill_keys( self::TRIGGERS, null );
        $purchase_min = 0.0;

        foreach ( self::TRIGGERS as $trigger ) {
            if ( CampaignTrigger::SIGNUP === $trigger && ! $can_register ) {
                continue;
            }

            $advert = $engine->advertised( $trigger, $ctx );

            if ( null === $advert ) {
                continue;
            }

            if ( CampaignTrigger::PURCHASE === $trigger ) {
                $earn[ $trigger ] = $advert['value'];
                $purchase_min     = PurchaseHandler::min_subtotal( $advert['campaign']->get_config() );
            } else {
                $earn[ $trigger ] = (int) $advert['value'];
            }
        }

        return [
            'earn'         => $earn,
            'purchase_min' => $purchase_min,
        ];
    }
}
