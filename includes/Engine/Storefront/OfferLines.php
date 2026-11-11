<?php
namespace YayLoyalty\Engine\Storefront;

use YayLoyalty\Engine\Points\Context;
use YayLoyalty\Engine\Points\PointsEngine;
use YayLoyalty\Helpers\DesignHelper;
use YayLoyalty\Models\Campaign;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * What one viewer is shown for a trigger: the value of their row, and the bonuses teased under it.
 * PointsEngine::offers() decides which campaigns are bonuses and their status; this class only picks
 * what to show and words the teaser. Every storefront surface reads it, so they agree.
 */
final class OfferLines {

    /**
     * Words a list of conditions as a clause ("for VIP members"), or leaves null when it can't.
     * Nothing subscribes in Lite. Internal Pro seam, not a public API.
     */
    public const PHRASE_FILTER = 'yayloyalty_offer_condition_phrase';

    /** Most bonuses teased under one row. */
    public const MAX_BONUSES = 3;

    // A campaign's or reward's `advert_mode`: the teaser worded from its conditions, the merchant's `advert_text`, or none.
    public const MODE_AUTO   = 'auto';
    public const MODE_CUSTOM = 'custom';
    public const MODE_OFF    = 'off';

    /** Longest `advert_text`. */
    public const TEXT_MAX = 120;

    /**
     * The config specs of `advert_mode` and `advert_text`, which every campaign trigger and reward type lists.
     * A missing `advert_mode` reads as MODE_AUTO.
     */
    public const CONFIG_SPECS = [
        'advert_mode' => [
            'type'   => 'enum',
            'values' => [ self::MODE_AUTO, self::MODE_CUSTOM, self::MODE_OFF ],
        ],
        'advert_text' => [
            'type'       => 'string',
            'max_length' => self::TEXT_MAX,
        ],
    ];

    /** @var array<string, array> for_trigger() per trigger, viewer and time, for this request. */
    private static $memo = [];

    /**
     * `row` is PointsEngine::offers()' row: what the viewer is sure to be paid, the base for a guest. `bonuses` are the
     * locked and at-checkout ones that pay more than the row and have a teaser, at most MAX_BONUSES. A guest
     * with no base gets none: there is no row to put them under.
     *
     * @param string       $trigger A CampaignTrigger constant.
     * @param Context      $advert  Context::advert() at the time the lines are for.
     * @param Context|null $viewer  The member; null for a guest.
     * @return array{base: array{campaign: Campaign, value: float}|null, row: array{campaign: Campaign, value: float}|null, bonuses: array<int, array{campaign: Campaign, value: float, status: string, results: array, teaser: string}>}
     */
    public static function for_trigger( PointsEngine $engine, string $trigger, Context $advert, ?Context $viewer ): array {
        $key = $trigger . '|' . self::viewer_key( $viewer ) . '|' . $advert->get_occurred_at();

        if ( ! isset( self::$memo[ $key ] ) ) {
            self::$memo[ $key ] = self::resolve( $engine, $trigger, $advert, $viewer );
        }

        return self::$memo[ $key ];
    }

    /**
     * The memo's key for a viewer: who it is, and what its lines and checkout inputs are, so two products or carts
     * in one request never share an answer. A Context without lines (from_user()) keeps one key per member.
     */
    private static function viewer_key( ?Context $viewer ): string {
        if ( null === $viewer ) {
            return 'guest';
        }

        $lines = [];

        foreach ( $viewer->get_line_items() as $line ) {
            $lines[] = [ $line->get_product_id(), $line->get_variation_id(), $line->get_quantity(), $line->get_subtotal() ];
        }

        return (int) $viewer->get_user_id() . ':' . md5(
            (string) wp_json_encode(
                [
                    $lines,
                    $viewer->get_order_subtotal(),
                    $viewer->get_extra( Context::EXTRA_PAYMENT_METHOD, '' ),
                    $viewer->get_extra( Context::EXTRA_BILLING_COUNTRY, '' ),
                    $viewer->get_extra( Context::EXTRA_SHIPPING_COUNTRY, '' ),
                ]
            )
        );
    }

    /**
     * The teaser for a campaign or reward: none when its `advert_mode` is off, its `advert_text` when custom,
     * else its conditions worded through PHRASE_FILTER.
     *
     * @param array $config     The item's config.
     * @param array $conditions The item's conditions.
     */
    public static function teaser( array $config, array $conditions ): ?string {
        $mode = (string) ( $config['advert_mode'] ?? self::MODE_AUTO );

        if ( self::MODE_OFF === $mode ) {
            return null;
        }

        if ( self::MODE_CUSTOM === $mode ) {
            $text = trim( (string) ( $config['advert_text'] ?? '' ) );

            return '' === $text ? null : $text;
        }

        /**
         * A clause for $conditions, or null when they can't all be worded.
         *
         * @param string|null $phrase
         * @param array       $conditions
         */
        $phrase = apply_filters( self::PHRASE_FILTER, null, $conditions ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant holds the prefixed name.

        return is_string( $phrase ) && '' !== trim( $phrase ) ? trim( $phrase ) : null;
    }

    /** Whether bonuses are teased: Design → Visibility → "Show bonus offers". */
    public static function enabled(): bool {
        return DesignHelper::surface_enabled( DesignHelper::get_design(), 'show_bonus_offers' );
    }

    private static function resolve( PointsEngine $engine, string $trigger, Context $advert, ?Context $viewer ): array {
        try {
            $offers = $engine->offers( $trigger, $advert, $viewer );
        } catch ( \Throwable $e ) {
            Log::warning( 'OfferLines', $trigger . ' offers failed: ' . $e->getMessage() );

            $base = LauncherEarnRules::advertised( $engine, $trigger, $advert );

            return [
                'base'    => $base,
                'row'     => $base,
                'bonuses' => [],
            ];
        }

        $row = $offers['row'];

        return [
            'base'    => $offers['base'],
            'row'     => $row,
            // A guest with no row has nothing to put bonuses under; a member with none may still earn one.
            'bonuses' => ( null === $viewer && null === $row ) || ! self::enabled() ? [] : self::teased( $offers['bonuses'], null === $row ? null : $row['value'] ),
        ];
    }

    /**
     * @param array<int, array{campaign: Campaign, value: float, status: string}> $bonuses
     * @return array<int, array{campaign: Campaign, value: float, status: string, teaser: string}>
     */
    private static function teased( array $bonuses, ?float $row_value ): array {
        $teased = [];

        foreach ( $bonuses as $bonus ) {
            if ( PointsEngine::OFFER_MATCHED === $bonus['status'] || ( null !== $row_value && $bonus['value'] <= $row_value ) ) {
                continue;
            }

            $teaser = self::teaser( $bonus['campaign']->get_config(), $bonus['campaign']->get_conditions() );

            if ( null === $teaser ) {
                continue;
            }

            $teased[] = $bonus + [ 'teaser' => $teaser ];

            if ( count( $teased ) >= self::MAX_BONUSES ) {
                break;
            }
        }

        return $teased;
    }
}
