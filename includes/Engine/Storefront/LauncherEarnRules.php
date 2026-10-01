<?php
namespace YayLoyalty\Engine\Storefront;

use YayLoyalty\Engine\Points\Triggers\PurchaseHandler;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Models\Campaign;

defined( 'ABSPATH' ) || exit;

/**
 * The "Ways to earn" rows the launcher lists, from the campaigns live right now.
 *
 * Only a campaign that applies to every shopper is advertised: the built-in one, or any campaign with no
 * conditions. A campaign with conditions (Pro) holds for some shoppers and not others, and a panel every
 * shopper sees must not promise a rate only some of them get. Within a trigger the first advertisable
 * campaign in engine order wins, the one that would pay. A multiplier purchase campaign is skipped:
 * it scales another campaign's rate rather than stating its own.
 */
final class LauncherEarnRules {

    /**
     * @param Campaign[] $live Campaigns live now, any trigger, in the engine's selection order.
     * @return array{purchase: ?float, signup: ?int, review: ?int} Points per currency unit, points per signup, points per review; null when not advertised.
     */
    public static function from_campaigns( array $live ): array {
        $rules = [
            CampaignTrigger::PURCHASE => null,
            CampaignTrigger::SIGNUP   => null,
            CampaignTrigger::REVIEW   => null,
        ];

        foreach ( $live as $campaign ) {
            $trigger = $campaign->get_trigger();

            if ( ! array_key_exists( $trigger, $rules ) || null !== $rules[ $trigger ] || ! self::applies_to_everyone( $campaign ) ) {
                continue;
            }

            $rules[ $trigger ] = CampaignTrigger::PURCHASE === $trigger
                ? self::purchase_rate( $campaign->get_config() )
                : self::flat_points( $campaign->get_config() );
        }

        return $rules;
    }

    /** Points per currency unit; null for a multiplier (it scales another campaign's rate) or a zero rate. */
    private static function purchase_rate( array $config ): ?float {
        if ( PurchaseHandler::is_multiplier( $config ) ) {
            return null;
        }

        $rate = (float) ( $config['points_per_unit'] ?? 0 );

        return $rate > 0 ? $rate : null;
    }

    private static function flat_points( array $config ): ?int {
        $points = (int) ( $config['points'] ?? 0 );

        return $points > 0 ? $points : null;
    }

    private static function applies_to_everyone( Campaign $campaign ): bool {
        return $campaign->is_builtin() || [] === $campaign->get_conditions();
    }
}
