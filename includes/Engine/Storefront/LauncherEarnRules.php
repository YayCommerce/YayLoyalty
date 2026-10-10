<?php
namespace YayLoyalty\Engine\Storefront;

use YayLoyalty\Engine\Points\Context;
use YayLoyalty\Engine\Points\PointsEngine;
use YayLoyalty\Engine\Points\Triggers\PurchaseHandler;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Models\Campaign;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * The "Ways to earn" rows the launcher lists. PointsEngine::advertised() decides each trigger's value,
 * never more than any shopper is paid; this class only shapes the rows.
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
     */
    public static function advertise( PointsEngine $engine, Context $ctx, bool $can_register ): array {
        $rules = self::none();

        foreach ( self::TRIGGERS as $trigger ) {
            if ( CampaignTrigger::SIGNUP === $trigger && ! $can_register ) {
                continue;
            }

            $advert = self::advertised( $engine, $trigger, $ctx );

            if ( null === $advert ) {
                continue;
            }

            if ( CampaignTrigger::PURCHASE === $trigger ) {
                $rules['earn'][ $trigger ] = $advert['value'];
                $rules['purchase_min']     = PurchaseHandler::min_subtotal( $advert['campaign']->get_config() );
            } else {
                $rules['earn'][ $trigger ] = (int) $advert['value'];
            }
        }

        return $rules;
    }

    /**
     * PointsEngine::advertised(), or null when it fails: the engine's filters run here on every storefront
     * page, and one broken subscriber must cost only its own trigger's row.
     *
     * @return array{campaign: Campaign, value: float}|null
     */
    private static function advertised( PointsEngine $engine, string $trigger, Context $ctx ): ?array {
        try {
            return $engine->advertised( $trigger, $ctx );
        } catch ( \Throwable $e ) {
            Log::warning( 'LauncherEarnRules', $trigger . ' earn row failed: ' . $e->getMessage() );

            return null;
        }
    }

    /**
     * A member's "Ways to earn" rows, in display order: no signup row, since a member already has the account it pays for.
     *
     * @param array{purchase: ?float, review: ?int} $earn         advertise()'s `earn`.
     * @param string                                $rate_line    The Design tab's earn_rate_line.
     * @param float                                 $purchase_min advertise()'s `purchase_min`.
     * @return array<int, array{trigger: string, label: string, value: string}>
     */
    public static function member_rows( array $earn, string $rate_line, float $purchase_min ): array {
        $rows = [];

        if ( null !== $earn[ CampaignTrigger::PURCHASE ] ) {
            $rows[] = [
                'trigger' => CampaignTrigger::PURCHASE,
                'label'   => __( 'Make a purchase', 'yayloyalty' ),
                'value'   => LauncherCopy::earn_rate( $rate_line, $earn[ CampaignTrigger::PURCHASE ], $purchase_min ),
            ];
        }

        if ( null !== $earn[ CampaignTrigger::REVIEW ] ) {
            $rows[] = [
                'trigger' => CampaignTrigger::REVIEW,
                'label'   => __( 'Write a review', 'yayloyalty' ),
                'value'   => SettingsHelper::points_text( $earn[ CampaignTrigger::REVIEW ] ),
            ];
        }

        return $rows;
    }

    /**
     * No earn rows: advertise()'s shape with nothing advertised.
     *
     * @return array{earn: array{purchase: null, signup: null, review: null}, purchase_min: float}
     */
    public static function none(): array {
        return [
            'earn'         => array_fill_keys( self::TRIGGERS, null ),
            'purchase_min' => 0.0,
        ];
    }
}
