<?php
namespace YayLoyalty\Engine\Earning\Listeners;

use YayLoyalty\Engine\Earning\EarningService;
use YayLoyalty\Engine\Earning\Reversal;
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

    private Reversal $reversal;

    /** Statuses that take a paid review's points back; `delete` is what wp_delete_comment() announces. */
    private const REVERSING_STATUSES = [ 'unapproved', 'spam', 'trash', 'delete' ];

    public function __construct( EarningService $service, LedgerRepository $ledger, Reversal $reversal ) {
        $this->service  = $service;
        $this->ledger   = $ledger;
        $this->reversal = $reversal;
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
        ListenerGuard::run( 'ReviewListener', 'review failed for review #' . $comment_id, fn() => $this->on_approved( $comment ) );
    }

    /**
     * `transition_comment_status` adapter: approval awards; leaving approved
     * for unapproved, spam, trash or a hard delete takes the points back.
     * Re-approval awards again only in name: the earn key is spent, so it is
     * a duplicate (no re-award after a reversal).
     */
    public function on_comment_transition( string $new_status, string $old_status, \WP_Comment $comment ): void {
        if ( ! self::is_product_review( $comment ) ) {
            return;
        }

        $subject = 'review #' . $comment->comment_ID;

        if ( 'approved' === $new_status ) {
            Log::debug( 'ReviewListener', $subject . ' ' . $old_status . ' -> approved' );
            ListenerGuard::run( 'ReviewListener', 'review failed for ' . $subject, fn() => $this->on_approved( $comment ) );
        } elseif ( 'approved' === $old_status && in_array( $new_status, self::REVERSING_STATUSES, true ) ) {
            Log::debug( 'ReviewListener', $subject . ' approved -> ' . $new_status );
            ListenerGuard::run( 'ReviewListener', 'reversal failed for ' . $subject, fn() => $this->on_unapproved( $comment ) );
        }
    }

    /** Awards the review campaign, scored at the comment's date against today's review earnings. */
    public function on_approved( \WP_Comment $comment ): void {
        $points_earned_today = $this->ledger->points_earned_today( (int) $comment->user_id, Dates::now(), LedgerSource::REVIEW );
        $outcome             = $this->service->award( CampaignTrigger::REVIEW, ContextFactory::from_comment( $comment, $points_earned_today ) );

        PreviewLog::write_outcome( 'ReviewListener', 'review #' . $comment->comment_ID, $outcome );
    }

    /** A product review, not a blog comment and not a reply in a review thread. */
    private static function is_product_review( \WP_Comment $comment ): bool {
        return 0 === (int) $comment->comment_parent && 'product' === get_post_type( (int) $comment->comment_post_ID );
    }

    /** Takes back everything a review earned; nothing when it never earned. */
    public function on_unapproved( \WP_Comment $comment ): void {
        $outcome = $this->reversal->full_review( (int) $comment->comment_ID );

        if ( null !== $outcome ) {
            PreviewLog::write_outcome( 'ReviewListener', 'review #' . $comment->comment_ID . ' reversal', $outcome );
        }
    }
}
