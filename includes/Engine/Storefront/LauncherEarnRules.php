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
     *         Points per unit of the shopper's currency / per signup / per review (null when not advertised),
     *         and the advertised purchase campaign's minimum order subtotal in the shopper's currency, so the
     *         rate line can say it (0 for none). Both convert from base through $ctx's base rate.
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
                $base_rate = $ctx->get_base_rate();
                $min       = PurchaseHandler::min_subtotal( $advert['campaign']->get_config() );

                // In the base currency both stay exact, as configured.
                $rules['earn'][ $trigger ] = LauncherCopy::converted_rate( $advert['value'] * $base_rate, $base_rate );
                $rules['purchase_min']     = round( $min / $base_rate, wc_get_price_decimals() );
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
     * Whether "Ways to earn" has nothing to print: no rows and no daily-limit line.
     *
     * @param array{rows: array[], limit_line: string} $earn for_viewer() or with_daily_limit().
     */
    public static function is_empty( array $earn ): bool {
        return [] === $earn['rows'] && '' === $earn['limit_line'];
    }

    /**
     * member_rows() under the daily limit: at 0 left the limit line replaces the rows; below it, the line follows rows there are.
     *
     * @param array[]  $rows       member_rows(), each as the yayloyalty_launcher_earn_rows filter documents it.
     * @param int|null $left_today EarningService::points_left_today(); null for no limit.
     * @return array{rows: array[], limit_line: string}
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
     * @param float                                 $purchase_min advertise()'s `purchase_min`.
     * @return array[] Each row as the yayloyalty_launcher_earn_rows filter documents it.
     */
    public static function member_rows( array $earn, float $purchase_min ): array {
        $rows = [];

        if ( null !== $earn[ CampaignTrigger::PURCHASE ] ) {
            $rows[] = [
                'trigger'     => CampaignTrigger::PURCHASE,
                'label'       => __( 'Make a purchase', 'yayloyalty' ),
                'value'       => LauncherCopy::earn_rate( $earn[ CampaignTrigger::PURCHASE ], $purchase_min ),
                /* translators: %s: the store's points label, as "Points". */
                'description' => sprintf( __( 'Earn %s on every order.', 'yayloyalty' ), SettingsHelper::points_label_plural() ),
            ];
        }

        if ( null !== $earn[ CampaignTrigger::REVIEW ] ) {
            $rows[] = [
                'trigger'     => CampaignTrigger::REVIEW,
                'label'       => __( 'Write a review', 'yayloyalty' ),
                'value'       => LauncherCopy::points_gain( $earn[ CampaignTrigger::REVIEW ] ),
                'description' => __( 'Share your experience after a purchase.', 'yayloyalty' ),
            ];
        }

        /**
         * Ways to earn rows for the current viewer, a guest's included. A row's optional `url` links its label,
         * and its optional `description` is the line under it on the Rewards tab.
         * Internal Pro seam, not a public API.
         *
         * @param array<int, array{trigger: string, label: string, value: string, description?: string, url?: string}> $rows
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
     * @return array{rows: array[], limit_line: string} Each row as the yayloyalty_launcher_earn_rows filter documents it.
     */
    public static function for_viewer( array $payload, int $user_id ): array {
        if ( ! Launcher::viewer_can_earn( $user_id ) ) {
            return self::with_daily_limit( [], null );
        }

        if ( $user_id > 0 ) {
            return self::with_daily_limit( self::member_rows( $payload['earn'], $payload['purchase_min'] ), $payload['left_today'] );
        }

        return [
            'rows'       => self::guest_rows( $payload ),
            'limit_line' => '',
        ];
    }

    /**
     * A guest's "Ways to earn" rows, signup first, with no earn gate: the guest panel, whose launcher already
     * checked it and whose Design tab preview shows the rows regardless.
     *
     * @param array $payload LauncherPayload::for_user() or for_balance() for a guest.
     * @return array[] Each row as the yayloyalty_launcher_earn_rows filter documents it.
     */
    public static function guest_rows( array $payload ): array {
        $earn = $payload['earn'];
        $rows = self::member_rows( $earn, $payload['purchase_min'] );

        if ( null !== $earn[ CampaignTrigger::SIGNUP ] ) {
            array_unshift(
                $rows,
                [
                    'trigger'     => CampaignTrigger::SIGNUP,
                    'label'       => LauncherCopy::create_account( $payload['register'] ),
                    'value'       => LauncherCopy::points_gain( $earn[ CampaignTrigger::SIGNUP ] ),
                    'description' => __( 'Join free and get a welcome bonus.', 'yayloyalty' ),
                ]
            );
        }

        return $rows;
    }

    /**
     * An earn row as rule-list-item.php prints it.
     *
     * @param array $row A row as the yayloyalty_launcher_earn_rows filter documents it.
     * @return array{icon: string, name: string, url: string, text: string, value: string}
     */
    public static function list_item( array $row ): array {
        return [
            'icon'  => AccountRewardsIcon::for_trigger( $row['trigger'] ),
            'name'  => $row['label'],
            'url'   => (string) ( $row['url'] ?? '' ),
            'text'  => (string) ( $row['description'] ?? '' ),
            'value' => $row['value'],
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
