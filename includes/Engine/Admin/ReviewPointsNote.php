<?php
namespace YayLoyalty\Engine\Admin;

use YayLoyalty\Engine\Earning\Listeners\ReviewListener;
use YayLoyalty\Enums\ReferenceType;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * A line under a review in Products → Reviews once its points were taken back. Re-approving never
 * re-awards (the earn key is spent, as for an order), and a comment has no notes, so this is where the
 * merchant learns to adjust by hand: the review's counterpart of OrderListener's reversed-earlier note.
 */
final class ReviewPointsNote {

    /** WooCommerce's filter on the Reviews table's comment column (ReviewsListTable::filter_column_output()). */
    private const COLUMN_FILTER = 'woocommerce_product_reviews_table_column_comment_content';

    public static function register(): void {
        add_filter( self::COLUMN_FILTER, [ self::class, 'append' ], 10, 2 );
    }

    /**
     * @param string|mixed $output The column's HTML.
     * @param mixed        $item   The review or reply being rendered.
     */
    public static function append( $output, $item ): string {
        $output = (string) $output;

        if ( ! ( $item instanceof \WP_Comment ) || ! ReviewListener::is_product_review( $item ) ) {
            return $output;
        }

        try {
            $reversed = self::reversed_points( (int) $item->comment_ID );
        } catch ( \Throwable $e ) {
            // The reviews screen outranks the note.
            Log::warning( 'ReviewPointsNote', 'review #' . $item->comment_ID . ': ' . $e->getMessage() );

            return $output;
        }

        if ( $reversed < 1 ) {
            return $output;
        }

        /* translators: %d: points */
        $note = sprintf( __( '%d points for this review were reversed when it was unpublished and are not awarded again (adjust manually if needed).', 'yayloyalty' ), $reversed );

        return $output . '<p class="yayloyalty-review-note"><em>' . esc_html( $note ) . '</em></p>';
    }

    /** What reversals took back from the review's earn; 0 when it never earned. */
    private static function reversed_points( int $comment_id ): int {
        $ledger = new LedgerRepository();
        $earn   = $ledger->find_earn_for_reference( ReferenceType::COMMENT, $comment_id );

        return null === $earn ? 0 : $ledger->sum_refunded_for( $earn, false );
    }
}
