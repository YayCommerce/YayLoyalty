<?php
namespace YayLoyalty\Engine\Storefront;

use YayLoyalty\Engine\Points\PointsEngine;
use YayLoyalty\Engine\Points\Triggers\PurchaseHandler;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Models\Campaign;

defined( 'ABSPATH' ) || exit;

/**
 * The "Ways to earn" rows the launcher lists, from the campaigns live right now.
 *
 * Only a campaign that applies to every shopper is advertised (PointsEngine::applies_to_everyone()):
 * a panel every shopper sees must not promise a rate only some of them get. Within a trigger the first advertisable
 * campaign in engine order wins, the one that would pay. A multiplier is stated as the rate it pays
 * (default rate × multiplier), and skipped when the engine would skip it.
 */
final class LauncherEarnRules {

    /** The triggers the launcher advertises, in display order: Lite's three. */
    public const TRIGGERS = [ CampaignTrigger::PURCHASE, CampaignTrigger::SIGNUP, CampaignTrigger::REVIEW ];

    /**
     * @param Campaign[]    $live             Campaigns live now, any trigger, in the engine's selection order.
     * @param Campaign|null $default_purchase The default purchase campaign a multiplier scales.
     * @return array{earn: array{purchase: ?float, signup: ?int, review: ?int}, purchase_min: float}
     *         Points per currency unit / per signup / per review (null when not advertised), and the
     *         advertised purchase campaign's minimum order subtotal so the rate line can say it (0 for none).
     */
    public static function advertise( array $live, ?Campaign $default_purchase ): array {
        $winners  = self::winners( $live, $default_purchase );
        $purchase = $winners[ CampaignTrigger::PURCHASE ];

        return [
            'earn'         => array_map(
                static function ( ?array $winner ) {
                    return null === $winner ? null : $winner['value'];
                },
                $winners
            ),
            'purchase_min' => null === $purchase ? 0.0 : PurchaseHandler::min_subtotal( $purchase['campaign']->get_config() ),
        ];
    }

    /**
     * @param Campaign[] $live
     * @return array<string, array{campaign: Campaign, value: float|int}|null>
     */
    private static function winners( array $live, ?Campaign $default_purchase ): array {
        $winners = array_fill_keys( self::TRIGGERS, null );

        foreach ( $live as $campaign ) {
            $trigger = $campaign->get_trigger();

            if ( ! array_key_exists( $trigger, $winners ) || null !== $winners[ $trigger ] || ! PointsEngine::applies_to_everyone( $campaign ) ) {
                continue;
            }

            $value = CampaignTrigger::PURCHASE === $trigger
                ? self::purchase_rate( $campaign->get_config(), $default_purchase )
                : self::flat_points( $campaign );

            if ( null !== $value ) {
                $winners[ $trigger ] = [
                    'campaign' => $campaign,
                    'value'    => $value,
                ];
            }
        }

        return $winners;
    }

    /** Points per currency unit the engine would pay; null when it would skip the campaign or pay nothing. */
    private static function purchase_rate( array $config, ?Campaign $default_purchase ): ?float {
        // A multiplier whose default the engine would refuse rates 0, and so is skipped like the engine skips it.
        $rate = PurchaseHandler::rate_of( $config, PurchaseHandler::multiplier_default( $default_purchase ) );

        return $rate > 0 ? $rate : null;
    }

    private static function flat_points( Campaign $campaign ): ?int {
        $points = $campaign->flat_points();

        return $points > 0 ? $points : null;
    }
}
