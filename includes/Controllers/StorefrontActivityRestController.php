<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Engine\Storefront\ShopperActivityRow;
use YayLoyalty\Repositories\LedgerRepository;

defined( 'ABSPATH' ) || exit;

/**
 * GET /me/activity: the signed-in shopper's latest ledger rows, for the storefront launcher.
 *
 * A shopper route, not an admin one: gated by session identity, so the user
 * is always the current one and there is no id param to enumerate. Fetched after the panel opens,
 * so a shopper who only wants their balance never waits on a ledger query.
 */
final class StorefrontActivityRestController extends AbstractRestController {

    /** Path under the namespace; the launcher builds its fetch URL from this. */
    public const ROUTE = '/me/activity';

    /** The launcher shows three rows; "View all" leads to My Account for the rest. Its skeleton draws as many. */
    public const ROWS = 3;

    /** @var LedgerRepository */
    private $ledger;

    public function __construct( LedgerRepository $ledger ) {
        $this->ledger = $ledger;
    }

    public function get_rest_base(): string {
        return 'me';
    }

    public function register_routes(): void {
        register_rest_route(
            self::REST_NAMESPACE,
            self::ROUTE,
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => fn( $request ) => $this->exec_read( [ $this, 'get_activity' ], $request ),
                    'permission_callback' => [ $this, 'is_signed_in' ],
                ],
                'schema' => [ $this, 'get_item_schema' ],
            ]
        );
    }

    /** @return true|\WP_Error */
    public function is_signed_in() {
        return is_user_logged_in()
            ? true
            : $this->error_unauthorized( __( 'Sign in to see your points activity.', 'yayloyalty' ) );
    }

    /** @return array{items: array<int, array{label: string, meta: string, points: int, delta: string}>} */
    public function get_activity( \WP_REST_Request $request ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- the user comes from the session.
        return [ 'items' => ShopperActivityRow::items( $this->ledger->latest_for_user( get_current_user_id(), self::ROWS ) ) ];
    }

    protected function define_item_schema(): array {
        $text = [
            'type'     => 'string',
            'context'  => [ 'view' ],
            'readonly' => true,
        ];

        return [
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'yayloyalty_shopper_activity',
            'type'       => 'object',
            'properties' => [
                'label'  => $text,
                'meta'   => [ 'description' => __( 'Date in the site format, then the order number when there is one.', 'yayloyalty' ) ] + $text,
                'points' => [ 'type' => 'integer' ] + $text,
                'delta'  => [ 'description' => __( 'Signed, formatted points: "+270" or "−120".', 'yayloyalty' ) ] + $text,
            ],
        ];
    }
}
