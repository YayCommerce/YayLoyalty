<?php
namespace YayLoyalty\Pro\Storefront;

use YayLoyalty\Engine\Points\Context;
use YayLoyalty\Engine\Points\ContextFactory;
use YayLoyalty\Engine\Points\PointsEngine;
use YayLoyalty\Engine\Points\Triggers\PurchaseHandler;
use YayLoyalty\Engine\Storefront\LauncherCopy;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Helpers\DesignHelper;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * The bonus offers in "Ways to earn": conditional campaigns the viewer could reach, beyond the rate they already
 * see. PointsEngine::advertised_offers() decides which can be paid; ConditionalOfferCopy which can be described.
 */
final class ConditionalOfferRows {

    /** Most offers one viewer sees. */
    private const MAX_ROWS = 3;

    /** The triggers with offers, in display order. */
    private const TRIGGERS = [ CampaignTrigger::PURCHASE, CampaignTrigger::REVIEW ];

    /** @var array<int, array[]> Offer rows by viewer, once per request since several placements can render on a page. */
    private static $rows = [];

    /** Called once by YayLoyaltyPro::boot(). */
    public function register(): void {
        add_filter( 'yayloyalty_launcher_earn_rows', [ $this, 'add_rows' ], 10, 3 );
    }

    /**
     * @param array[] $rows      Each row as the yayloyalty_launcher_earn_rows filter documents it (LauncherEarnRules::member_rows()).
     * @param array   $earn      LauncherEarnRules::advertise()'s `earn`.
     * @param int     $viewer_id The viewer the rows are for; 0 for a guest.
     * @return array[]
     */
    public function add_rows( array $rows, array $earn, int $viewer_id ): array {
        if ( ! DesignHelper::surface_enabled( DesignHelper::get_design(), 'show_bonus_offers' ) ) {
            return $rows;
        }

        if ( ! isset( self::$rows[ $viewer_id ] ) ) {
            self::$rows[ $viewer_id ] = self::offer_rows( $earn, $viewer_id );
        }

        return array_merge( $rows, self::$rows[ $viewer_id ] );
    }

    /**
     * @param array $earn      LauncherEarnRules::advertise()'s `earn`.
     * @param int   $viewer_id The viewer; 0 for a guest.
     * @return array[]
     */
    private static function offer_rows( array $earn, int $viewer_id ): array {
        try {
            $now    = Dates::now();
            $ctx    = $viewer_id > 0 ? ContextFactory::for_member_advert( $now, $viewer_id ) : ContextFactory::for_advert( $now );
            $engine = PointsEngine::build( new CampaignRepository() );
            $rows   = [];

            foreach ( self::TRIGGERS as $trigger ) {
                foreach ( $engine->advertised_offers( $trigger, $ctx ) as $offer ) {
                    $row = self::row( $trigger, $offer, $earn[ $trigger ] ?? null, $ctx );

                    if ( null !== $row ) {
                        $rows[] = $row;
                    }
                }
            }

            return array_slice( $rows, 0, self::MAX_ROWS );
        } catch ( \Throwable $e ) {
            Log::warning( 'ConditionalOfferRows', 'offer rows failed: ' . $e->getMessage() );

            return [];
        }
    }

    /**
     * @param string                                                $trigger A CampaignTrigger constant.
     * @param array{campaign: \YayLoyalty\Models\Campaign, value: float} $offer   One of PointsEngine::advertised_offers().
     * @param float|int|null                                        $base    What the trigger's own row already promises.
     * @param Context                                               $ctx     The viewer's advert.
     * @return array|null A row, or null when the offer is no better or has no words.
     */
    private static function row( string $trigger, array $offer, $base, Context $ctx ): ?array {
        $campaign = $offer['campaign'];
        $factor   = $ctx->get_to_base_factor();
        $value    = CampaignTrigger::PURCHASE === $trigger ? LauncherCopy::converted_rate( $offer['value'] * $factor, $factor ) : $offer['value'];
        $label    = ConditionalOfferCopy::label( $campaign );

        if ( null === $label || ( null !== $base && $value <= $base ) ) {
            return null;
        }

        if ( CampaignTrigger::PURCHASE === $trigger ) {
            $config = $campaign->get_config();
            $min    = round( PurchaseHandler::min_subtotal( $config ) / $factor, wc_get_price_decimals() );
            $text   = LauncherCopy::earn_rate( $value, $min, PurchaseHandler::rounding( $config ) );
        } else {
            $text = LauncherCopy::points_gain( (int) $value );
        }

        return [
            'trigger'     => $trigger,
            'label'       => $label,
            'value'       => $text,
            'description' => __( 'Bonus offer.', 'yayloyalty' ),
        ];
    }
}
