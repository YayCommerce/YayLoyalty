<?php
namespace YayLoyalty\Engine\Storefront;

use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * The signed-in shopper's whole points history as a CSV download, from the My Account Rewards tab.
 */
final class AccountHistoryCsv {

    public const QUERY_ARG = 'yayloyalty_export';

    public const NONCE_ACTION = 'yayloyalty_export_history';

    private const BATCH = 500;

    public static function url(): string {
        return wp_nonce_url( add_query_arg( self::QUERY_ARG, 'csv', AccountRewards::url() ), self::NONCE_ACTION );
    }

    /** On `template_redirect`: streams the file and exits when the request asks for it. */
    public static function maybe_send(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce is verified below, once the request is known to be an export.
        if ( ! isset( $_GET[ self::QUERY_ARG ] ) || 'csv' !== $_GET[ self::QUERY_ARG ] || ! is_user_logged_in() || ! is_wc_endpoint_url( AccountRewards::ENDPOINT ) ) {
            return;
        }

        if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), self::NONCE_ACTION ) ) {
            wp_die( esc_html__( 'This download link has expired. Reload the page and try again.', 'yayloyalty' ), '', [ 'response' => 403 ] );
        }

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="points-history-' . Dates::to_site_tz( Dates::now(), 'Y-m-d' ) . '.csv"' );

        $out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming to the response.
        fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UTF-8 BOM, so spreadsheets read the labels' accents.
        fputcsv( $out, [ __( 'Date', 'yayloyalty' ), __( 'Description', 'yayloyalty' ), __( 'Order', 'yayloyalty' ), __( 'Points', 'yayloyalty' ), __( 'Balance', 'yayloyalty' ) ] );

        $ledger  = new LedgerRepository();
        $user_id = get_current_user_id();
        $page    = 1;

        do {
            $batch = $ledger->paginate_for_user(
                $user_id,
                [
                    'page'     => $page,
                    'per_page' => self::BATCH,
                ]
            );

            foreach ( ShopperActivityRow::history_rows( $batch['items'] ) as $row ) {
                fputcsv(
                    $out,
                    [
                        Dates::to_site_tz( $row['created_at'], 'Y-m-d H:i' ),
                        self::cell( $row['label'] ),
                        self::cell( $row['order'] ),
                        $row['points'],
                        $row['balance'],
                    ]
                );
            }

            ++$page;
        } while ( ( $page - 1 ) * self::BATCH < (int) $batch['total'] );

        fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the stream opened above.
        exit;
    }

    /** A text cell a spreadsheet won't run as a formula. */
    private static function cell( string $text ): string {
        return '' !== $text && false !== strpos( "=+-@\t\r", $text[0] ) ? "'" . $text : $text;
    }
}
