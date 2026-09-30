<?php
namespace YayLoyalty\Engine\Earning\Listeners;

use YayLoyalty\Engine\Earning\EarningService;
use YayLoyalty\Engine\Points\ContextFactory;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * The review event family: one award per approved product review, keyed
 * "earn:{user}:comment:{id}:review". Approval can arrive on comment_post or on a
 * later status transition; both build the same Context.
 */
final class ReviewListener {

    private EarningService $service;

    /** For points_earned_today, the one ledger read the Context needs. */
    private LedgerRepository $ledger;

    public function __construct( EarningService $service, LedgerRepository $ledger ) {
        $this->service = $service;
        $this->ledger  = $ledger;
    }

    /**
     * Attaches comment_post (approved on arrival) and transition_comment_status
     * (approved later). comment_post runs at 20: WooCommerce writes the
     * `verified` meta on the same hook at 10, and reading before it would
     * score every review as unverified.
     */
    public function register(): void {
        add_action( 'comment_post', [ $this, 'on_comment_post' ], 20, 2 );
        add_action( 'transition_comment_status', [ $this, 'on_comment_transition' ], 10, 3 );
    }

    /**
     * `comment_post` adapter — fires when a review is approved on arrival.
     *
     * @param int|string $approved WordPress passes 1, 0 or 'spam'.
     */
    public function on_comment_post( int $comment_id, $approved ): void {
        if ( 1 !== (int) $approved ) {
            return;
        }

        $comment = get_comment( $comment_id );

        if ( ! ( $comment instanceof \WP_Comment ) || ! self::is_product_review( $comment ) ) {
            return;
        }

        Log::debug( 'ReviewListener', 'review #' . $comment_id . ' approved on arrival' );

        try {
            $this->on_approved( $comment );
        } catch ( \Throwable $e ) { // A listener must never break a comment save.
            Log::error( 'ReviewListener', 'review failed for review #' . $comment_id . ': ' . $e->getMessage() );
        }
    }

    /** `transition_comment_status` adapter — fires when a review is approved on a later status change. */
    public function on_comment_transition( string $new_status, string $old_status, \WP_Comment $comment ): void {
        if ( 'approved' !== $new_status || ! self::is_product_review( $comment ) ) {
            return;
        }

        Log::debug( 'ReviewListener', 'review #' . $comment->comment_ID . ' ' . $old_status . ' -> approved' );

        try {
            $this->on_approved( $comment );
        } catch ( \Throwable $e ) { // A listener must never break a comment status change.
            Log::error( 'ReviewListener', 'review failed for review #' . $comment->comment_ID . ': ' . $e->getMessage() );
        }
    }

    /** Awards the review campaign, scored at the comment's date against today's review earnings. */
    public function on_approved( \WP_Comment $comment ): void {
        $today   = $this->ledger->points_earned_today( (int) $comment->user_id, Dates::now(), LedgerSource::REVIEW );
        $outcome = $this->service->award( CampaignTrigger::REVIEW, ContextFactory::from_comment( $comment, $today ) );

        PreviewLog::write_outcome( 'ReviewListener', 'review #' . $comment->comment_ID, $outcome );
    }

    /** A product review, not a blog comment and not a reply in a review thread. */
    private static function is_product_review( \WP_Comment $comment ): bool {
        return 0 === (int) $comment->comment_parent && 'product' === get_post_type( (int) $comment->comment_post_ID );
    }

    /** Clawback seam for a review that is unapproved or deleted after paying; attaches with the refund design. */
    public function on_unapproved( \WP_Comment $comment ): void {
        // TODO(F01-4): refund_earn keyed on the comment — design pending with OrderListener::on_refunded.
    }
}
