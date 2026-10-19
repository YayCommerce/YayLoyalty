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
    public static function advertised( PointsEngine $engine, string $trigger, Context $ctx ): ?array {
        try {
            return $engine->advertised( $trigger, $ctx );
        } catch ( \Throwable $e ) {
            Log::warning( 'LauncherEarnRules', $trigger . ' earn row failed: ' . $e->getMessage() );

            return null;
        }
    }

    /**
     * member_rows() under the daily limit: at 0 left the limit line replaces the rows; below it, the line follows rows there are.
     *
     * @param array<int, array{trigger: string, label: string, value: string, url?: string}> $rows       member_rows().
     * @param int|null                                                         $left_today EarningService::points_left_today(); null for no limit.
     * @return array{rows: array<int, array{trigger: string, label: string, value: string, url?: string}>, limit_line: string}
     */
    public static function with_daily_limit( array $rows, ?int $left_today ): array {
        if ( 0 === $left_today ) {
            return [
                'rows'       => [],
                'limit_line' => LauncherCopy::daily_limit_line( 0 ),
            ];
        }

        return [
            'rows'       => $rows,
            'limit_line' => [] === $rows ? '' : LauncherCopy::daily_limit_line( $left_today ),
        ];
    }

    /**
     * A member's "Ways to earn" rows, in display order: no signup row, since a member already has the account it pays for.
     *
     * @param array{purchase: ?float, review: ?int} $earn         advertise()'s `earn`.
     * @param string                                $rate_line    The Design tab's earn_rate_line.
     * @param float                                 $purchase_min advertise()'s `purchase_min`.
     * @return array<int, array{trigger: string, label: string, value: string, url?: string}>
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

        /**
         * Ways to earn rows for the current viewer, a guest's included. A row's optional `url` links its label.
         * Internal Pro seam, not a public API.
         *
         * @param array<int, array{trigger: string, label: string, value: string, url?: string}> $rows
         * @param array<string, float|int|null>                                                   $earn advertise()'s `earn`.
         */
        return apply_filters( 'yayloyalty_launcher_earn_rows', $rows, $earn );
    }

    /**
     * The "Ways to earn" a viewer is shown outside the launcher: a guest's rows, signup first, or a member's
     * under the daily limit; none for a viewer who cannot earn.
     *
     * @param array $payload LauncherPayload::for_user() for this viewer.
     * @param int   $user_id The viewer; 0 for a guest.
     * @return array{rows: array<int, array{trigger: string, label: string, value: string, url?: string}>, limit_line: string}
     */
    public static function for_viewer( array $payload, int $user_id ): array {
        if ( ! Launcher::viewer_can_earn( $user_id ) ) {
            return self::with_daily_limit( [], null );
        }

        $earn = $payload['earn'];
        $rows = self::member_rows( $earn, $payload['text']['earn_rate_line'], $payload['purchase_min'] );

        if ( $user_id > 0 ) {
            return self::with_daily_limit( $rows, $payload['left_today'] );
        }

        if ( null !== $earn[ CampaignTrigger::SIGNUP ] ) {
            array_unshift(
                $rows,
                [
                    'trigger' => CampaignTrigger::SIGNUP,
                    'label'   => LauncherCopy::create_account( $payload['register'] ),
                    'value'   => SettingsHelper::points_text( $earn[ CampaignTrigger::SIGNUP ] ),
                ]
            );
        }

        return [
            'rows'       => $rows,
            'limit_line' => '',
        ];
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
