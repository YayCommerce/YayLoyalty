<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Controllers\Schemas\OrderPointsRouteArgs;
use YayLoyalty\Engine\Admin\OrderMissingPoints;
use YayLoyalty\Engine\Earning\EarningService;
use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Earning\Reversal;
use YayLoyalty\Engine\Emails\Emails;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Repositories\LedgerRepository;

defined( 'ABSPATH' ) || exit;

/** POST /orders/{id}/grant-points: grants the points a completed order missed, through EarningService::grant_for_order(). */
final class OrderPointsRestController extends AbstractRestController {

    /** @var LedgerRepository Read-only here. */
    private $ledger;

    public function __construct( LedgerRepository $ledger ) {
        $this->ledger = $ledger;
    }

    public function get_rest_base(): string {
        return 'orders';
    }

    public function register_routes(): void {
        register_rest_route(
            self::REST_NAMESPACE,
            '/orders/(?P<id>[\d]+)/grant-points',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => fn( $request ) => $this->exec_write( [ $this, 'grant_points' ], $request ),
                'permission_callback' => $this->requires( Capabilities::MANAGE_POINTS ),
                'args'                => OrderPointsRouteArgs::grant(),
            ]
        );
    }

    /** Built here, not in RestAPI::controllers(), so other REST requests never build the earning root. */
    public function grant_points( \WP_REST_Request $request ) {
        $order = wc_get_order( (int) $request['id'] );

        if ( ! $order instanceof \WC_Order ) {
            return $this->error_not_found( __( 'Order not found.', 'yayloyalty' ) );
        }

        if ( ! EarningService::is_missing_points( $order, $this->ledger ) ) {
            return $this->error_conflict( __( 'This order already has points, or is not a completed order of a registered customer.', 'yayloyalty' ) );
        }

        $note = trim( (string) $request['note'] );

        // actor_id comes from the session, never the request body.
        $outcome = EarningService::build()->grant_for_order(
            $order,
            (int) $request['points'],
            get_current_user_id(),
            '' === $note ? __( 'Missing order points', 'yayloyalty' ) : $note
        );

        if ( Outcome::SKIPPED === $outcome->get_status() ) {
            return $this->error_conflict( OrderMissingPoints::reason_label( $outcome->get_reason() ) );
        }

        if ( Outcome::AWARDED !== $outcome->get_status() ) {
            return $this->error_conflict( __( 'Points for this order were already granted.', 'yayloyalty' ) );
        }

        $entry = $outcome->get_entry();

        $clawed_back = 0;

        foreach ( Reversal::build()->settle_earlier_refunds( $order ) as $reversal ) {
            $clawed_back += $reversal->get_entry() && Outcome::AWARDED === $reversal->get_status() ? -$reversal->get_entry()->get_points() : 0;
        }

        if ( (bool) $request['notify'] ) {
            Emails::relay_grant( $entry );
        }

        return [
            'points'      => $entry->get_points(),
            'points_text' => SettingsHelper::points_text( $entry->get_points() ),
            'clawed_back' => $clawed_back,
            'entry_id'    => $entry->get_id(),
        ];
    }
}
