<?php
namespace YayLoyalty\Engine\Storefront;

use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Enums\Status;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Models\Reward;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Text;

defined( 'ABSPATH' ) || exit;

/**
 * The Design tab's preview: a whole HTML page with the real launcher markup and stylesheet in a plain store frame.
 * The admin shows it in a sandboxed iframe, so the storefront CSS applies at the iframe's width. No script runs
 * there: the templates print the expanded state and the activity rows, so the preview never takes focus from
 * the field being edited. The panel body scrolls as on the storefront, but no control takes a click, so a link
 * never loads a page into the frame. Rates, the discount reward and copy are the store's own. The shopper, their
 * history and the reward catalog are samples.
 */
final class LauncherPreview {

    /** The sample member's balance. */
    public const SAMPLE_BALANCE = 9850;

    /** The store around the launcher, linked by document(): flat greys, so only the launcher reads at full contrast. */
    private const FRAME_STYLE_PATH = 'assets/storefront/launcher-preview.css';

    /**
     * The button alone and the open panel, from one view.
     *
     * @param array $design    DesignHelper::with_unsaved().
     * @param bool  $is_member A signed-in sample shopper, or a guest.
     * @return array{collapsed: string, expanded: string}
     */
    public static function documents( array $design, bool $is_member ): array {
        $payload = ( new LauncherPayload( new CampaignRepository(), new RewardRepository(), new CustomerRepository() ) )
            ->for_balance( $design, $is_member ? self::SAMPLE_BALANCE : null );

        // Sample rewards, so the reward catalog previews before any exists; the panel switch still hides them.
        $payload['offers'] = array_map( [ LauncherPayload::class, 'offer' ], self::sample_rewards() );
        $view              = Launcher::view( $design, $payload, false );

        if ( $is_member ) {
            $view['activity'] = ShopperActivityRow::items( self::sample_entries() );
        }

        return [
            'collapsed' => self::document( $view + [ 'is_open' => false ] ),
            'expanded'  => self::document( $view + [ 'is_open' => true ] ),
        ];
    }

    private static function document( array $view ): string {
        return '<!doctype html><html><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<link rel="stylesheet" href="' . esc_url( Launcher::style_url() ) . '">' // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- a standalone page for an iframe; there is no WordPress head to enqueue into.
            . '<link rel="stylesheet" href="' . esc_url( AccountMyPoints::style_url() ) . '">' // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- as above: the panel prints the My Points tab's partials.
            . '<link rel="stylesheet" href="' . esc_url( Launcher::versioned_url( self::FRAME_STYLE_PATH ) ) . '">' // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- as above.
            . '</head><body>'
            . self::store_frame()
            . Launcher::markup( $view )
            . '</body></html>';
    }

    private static function store_frame(): string {
        return '<div class="yl-preview-header"><strong>' . esc_html( Text::store_name() ) . '</strong><span>' . esc_html__( 'Shop', 'yayloyalty' ) . '</span><span>' . esc_html__( 'Cart', 'yayloyalty' ) . '</span></div>'
            . '<div class="yl-preview-lines"><i style="width:38%"></i><i style="width:62%"></i><i style="width:52%"></i></div>';
    }

    /**
     * The member panel's sample history, newest first: an order earning, a redemption, a review.
     *
     * @return LedgerEntry[]
     */
    private static function sample_entries(): array {
        return [
            self::sample_entry( 270, LedgerType::EARN, LedgerSource::ORDER, 4207, 2 ),
            self::sample_entry( -120, LedgerType::REDEEM, LedgerSource::ORDER, 4180, 9 ),
            self::sample_entry( 50, LedgerType::EARN, LedgerSource::REVIEW, null, 16 ),
        ];
    }

    /**
     * The reward catalog's samples, one per coupon type this build offers: none in a build that offers neither.
     *
     * @return Reward[]
     */
    private static function sample_rewards(): array {
        $offered = RewardType::all();
        $rewards = [];

        if ( in_array( RewardType::FIXED_COUPON, $offered, true ) ) {
            /* translators: %s: money amount, as "$5". */
            $name      = sprintf( __( '%s off', 'yayloyalty' ), LauncherCopy::money( 5.0 ) );
            $rewards[] = self::sample_reward(
                $name,
                RewardType::FIXED_COUPON,
                500,
                [
                    'amount'    => 5,
                    'min_order' => 25,
                ]
            );
        }

        if ( in_array( RewardType::PERCENT_COUPON, $offered, true ) ) {
            /* translators: %s: a percentage number, as "15". */
            $name      = sprintf( __( '%s%% off', 'yayloyalty' ), LauncherCopy::rate( 15.0 ) );
            $rewards[] = self::sample_reward(
                $name,
                RewardType::PERCENT_COUPON,
                1000,
                [
                    'percent'      => 15,
                    'max_discount' => 10,
                ]
            );
        }

        return $rewards;
    }

    private static function sample_reward( string $name, string $type, int $cost, array $config ): Reward {
        return new Reward( null, $name, '', $type, Status::ACTIVE, 0, $cost, $config );
    }

    private static function sample_entry( int $points, string $type, string $source, ?int $order_id, int $days_ago ): LedgerEntry {
        return new LedgerEntry( null, 0, $points, 0, $type, $source, null, null, null, null, $order_id, null, null, Dates::offset( -$days_ago * DAY_IN_SECONDS ) );
    }
}
