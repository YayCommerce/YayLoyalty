<?php
namespace YayLoyalty\Engine\Storefront;

use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Models\LedgerEntry;
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
 * the field being edited. Rates, rewards and copy are the store's own. Only the shopper is a sample.
 */
final class LauncherPreview {

    /** The sample member's balance. */
    public const SAMPLE_BALANCE = 9850;

    /** The store around the launcher: flat greys, so only the launcher reads at full contrast. */
    private const FRAME_CSS = <<<'CSS'
html,body{margin:0;height:100%;overflow:hidden}
body{background:#f7f7f7;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
.yl-preview-header{display:flex;align-items:center;gap:18px;height:44px;padding:0 20px;background:#ededed;border-bottom:1px solid #e0e0e0;font-size:13px;color:#a8a8a8}
.yl-preview-header strong{color:#8a8a8a;letter-spacing:.06em;text-transform:uppercase}
.yl-preview-header span:last-child{margin-left:auto}
.yl-preview-lines{display:flex;flex-direction:column;gap:10px;padding:24px 20px}
.yl-preview-lines i{display:block;height:12px;background:#e6e6e6}
[data-yl-highlighted]{outline:2px solid #f97316;outline-offset:2px;border-radius:4px}
CSS;

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
        $view    = Launcher::view( $design, $payload, false );

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
            . '<style>' . self::FRAME_CSS . '</style></head><body>'
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

    private static function sample_entry( int $points, string $type, string $source, ?int $order_id, int $days_ago ): LedgerEntry {
        return new LedgerEntry( null, 0, $points, 0, $type, $source, null, null, null, null, $order_id, null, null, Dates::offset( -$days_ago * DAY_IN_SECONDS ) );
    }
}
