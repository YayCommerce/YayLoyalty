<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Controllers\Schemas\OrderPointsRouteArgs;
use YayLoyalty\Database\Transaction;
use YayLoyalty\Engine\Admin\OrderMissingPoints;
use YayLoyalty\Engine\Earning\EarningService;
use YayLoyalty\Engine\Earning\Outcome;
use YayLoyalty\Engine\Earning\Reversal;
use YayLoyalty\Engine\Emails\Emails;
use YayLoyalty\Engine\Redemption\RedemptionService;
use YayLoyalty\Engine\Redemption\RewardMismatch;
use YayLoyalty\Engine\Redemption\SessionIntent;
use YayLoyalty\Engine\Rewards\Result as RedeemResult;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Repositories\LedgerRepository;

defined( 'ABSPATH' ) || exit;

/**
 * POST /orders/{id}/grant-points: grants the points a completed order missed, through EarningService::grant_for_order().
 * POST /orders/{id}/reconcile-reward: repairs one coupon line or redemption row that disagree, through RedemptionService::repair_mismatch().
 */
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

        register_rest_route(
            self::REST_NAMESPACE,
            '/orders/(?P<id>[\d]+)/reconcile-reward',
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => fn( $request ) => $this->exec_write( [ $this, 'reconcile_reward' ], $request ),
                'permission_callback' => $this->requires( Capabilities::MANAGE_POINTS ),
                'args'                => OrderPointsRouteArgs::reconcile(),
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

    /** The action and the points come from the order as it stands now, never the request. Built here for the same reason as grant_points(). */
    public function reconcile_reward( \WP_REST_Request $request ) {
        $order = wc_get_order( (int) $request['id'] );

        if ( ! $order instanceof \WC_Order ) {
            return $this->error_not_found( __( 'Order not found.', 'yayloyalty' ) );
        }

        $mismatch = RewardMismatch::build()->find( $order, (string) $request['code'] );

        if ( null === $mismatch ) {
            return $this->error_conflict( __( 'This reward no longer needs a repair.', 'yayloyalty' ) );
        }

        $outcome = RedemptionService::build( new SessionIntent() )->repair_mismatch( $order, $mismatch );

        if ( RedeemResult::REASON_INSUFFICIENT_BALANCE === $outcome->get_reason() ) {
            return $this->error_conflict( __( 'The customer\'s balance is too low to debit these points.', 'yayloyalty' ) );
        }

        if ( Outcome::AWARDED !== $outcome->get_status() ) {
            return $this->error_conflict( __( 'This reward could not be repaired.', 'yayloyalty' ) );
        }

        $points = abs( $outcome->get_entry()->get_points() );
        $text   = SettingsHelper::points_text( $points );
        $actor  = wp_get_current_user()->display_name;
        $name   = null === $mismatch['reward'] ? $mismatch['code'] : $mismatch['reward']->get_name();
        $note   = RewardMismatch::KIND_UNPAID_LINE === $mismatch['kind']
            /* translators: 1: points in the store's format, as "500 Points", 2: the reward's name, 3: admin's display name */
            ? sprintf( __( '%1$s debited for %2$s by %3$s: the order carried its discount without a debit.', 'yayloyalty' ), $text, $name, $actor )
            /* translators: 1: points in the store's format, as "500 Points", 2: the reward's name, 3: admin's display name */
            : sprintf( __( '%1$s restored for %2$s by %3$s: the order no longer carried its discount.', 'yayloyalty' ), $text, $name, $actor );

        Transaction::after_commit(
            static function () use ( $order, $note ): void {
                $order->add_order_note( $note );
            }
        );

        return [
            'points'      => $points,
            'points_text' => $text,
            'kind'        => $mismatch['kind'],
        ];
    }
}
