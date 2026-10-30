<?php
namespace YayLoyalty\Engine\Storefront;

use YayLoyalty\Engine\Earning\ProgramGate;

defined( 'ABSPATH' ) || exit;

/**
 * The My Account history's filter tabs and page links, loaded in place: answers the "Points history" section
 * alone, so assets/storefront/points-history.js swaps it without fetching the whole page.
 */
final class AccountHistoryAjax {

    public const ACTION = 'yayloyalty_points_history';

    /** Not `page`, which WordPress reads as the main query's page. */
    public const PAGE_QUERY_ARG = 'history_page';

    public static function url(): string {
        return \WC_AJAX::get_endpoint( self::ACTION );
    }

    /**
     * GET `history_page` and `points_type`, as the tab's own links. A read of the viewer's own history, so it
     * needs only the login cookie; the same gates as AccountMyPoints::render(). Answers `{ html }`.
     */
    public static function respond(): void {
        $user_id = get_current_user_id();

        if ( 0 === $user_id || ! ProgramGate::program_enabled() || ! Launcher::viewer_can_earn( $user_id ) ) {
            wp_send_json_error( null, 403 );
        }

        $page = max( 1, absint( wp_unslash( $_GET[ self::PAGE_QUERY_ARG ] ?? 1 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only view.
        $html = OutputCapture::capture(
            static function () use ( $user_id, $page ): void {
                $yayloyalty_history = AccountMyPoints::history_view( $user_id, $page );

                include __DIR__ . '/templates/points-history.php';
            },
            'AccountHistoryAjax'
        );

        if ( null === $html ) {
            wp_send_json_error( null, 500 );
        }

        wp_send_json_success( [ 'html' => $html ] );
    }
}
