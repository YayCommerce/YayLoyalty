<?php
namespace YayLoyalty\Engine\Earning\Listeners;

use YayLoyalty\Engine\Earning\EarningService;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * The review event family: one award per approved product review, keyed
 * "earn:{user}:comment:{id}:review". Approval can arrive on comment_post or on a
 * later status transition; both build the same Context. The campaign's
 * one_per_product rule is a Gate on the review trigger, not a handler
 * concern (ReviewHandler decides require_verified only).
 */
final class ReviewListener {

    private EarningService $service;

    public function __construct( EarningService $service ) {
        $this->service = $service;
    }

    /** Attaches comment_post (approved on arrival) and transition_comment_status (approved later). */
    public function register(): void {
        add_action( 'comment_post', [ $this, 'on_comment_post' ], 10, 2 );
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
        } catch ( \RuntimeException $e ) {
            // TODO(step-3): remove this catch once on_approved() is implemented.
            Log::warning( 'ReviewListener', 'award skipped for review #' . $comment_id . ': ' . $e->getMessage() );
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
        } catch ( \RuntimeException $e ) {
            // TODO(step-3): remove this catch once on_approved() is implemented.
            Log::warning( 'ReviewListener', 'award skipped for review #' . $comment->comment_ID . ': ' . $e->getMessage() );
        }
    }

    public function on_approved( \WP_Comment $comment ): void {
        // TODO(F01-4): ContextFactory::from_comment( comment ); service->award( REVIEW, ctx ).
    }

    /** A product review, not a blog comment. */
    private static function is_product_review( \WP_Comment $comment ): bool {
        return 'product' === get_post_type( (int) $comment->comment_post_ID );
    }

    /** Clawback seam for a review that is unapproved or deleted after paying; attaches with the refund design. */
    public function on_unapproved( \WP_Comment $comment ): void {
        // TODO(F01-4): refund_earn keyed on the comment — design pending with OrderListener::on_refunded.
    }
}
