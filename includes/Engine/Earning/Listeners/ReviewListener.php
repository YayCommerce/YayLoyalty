<?php
namespace YayLoyalty\Engine\Earning\Listeners;

use YayLoyalty\Engine\Earning\EarningService;

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
        // TODO(F01-4): add_action comment_post + transition_comment_status → on_approved when the comment is a product review reaching 'approved'.
    }

    public function on_approved( \WP_Comment $comment ): void {
        // TODO(F01-4): ContextFactory::from_comment( comment ); service->award( REVIEW, ctx ).
    }

    /** Clawback seam for a review that is unapproved or deleted after paying; attaches with the refund design. */
    public function on_unapproved( \WP_Comment $comment ): void {
        // TODO(F01-4): refund_earn keyed on the comment — design pending with OrderListener::on_refunded.
    }
}
