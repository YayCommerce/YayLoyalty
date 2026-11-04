<?php
namespace YayLoyalty\Pro\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Controllers\AbstractRestController;
use YayLoyalty\Models\Customer;
use YayLoyalty\Models\Tier;
use YayLoyalty\Pro\Services\TierEvaluator;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\TierRepository;

defined( 'ABSPATH' ) || exit;

/**
 * One customer's tier for the drawer card, and the manual floor staff set or remove there. Writes go
 * through TierEvaluator, the only writer of the tier columns; this controller only reads them.
 */
final class CustomerTierRestController extends AbstractRestController {

    /** @var CustomerRepository */
    private $customers;

    /** @var TierRepository */
    private $tiers;

    public function __construct( CustomerRepository $customers, TierRepository $tiers ) {
        $this->customers = $customers;
        $this->tiers     = $tiers;
    }

    public function get_rest_base(): string {
        return 'customers';
    }

    public function register_routes(): void {
        register_rest_route(
            self::REST_NAMESPACE,
            '/customers/(?P<id>[\d]+)/tier',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => fn( $request ) => $this->exec_read( [ $this, 'get_tier' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::VIEW ),
                    'args'                => [ 'id' => $this->member_id_param() ],
                ],
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => fn( $request ) => $this->exec_write( [ $this, 'assign_tier' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::MANAGE_TIERS ),
                    'args'                => [
                        'id'      => $this->user_id_param(),
                        'tier_id' => [
                            'description' => __( 'The live tier the customer is kept at or above.', 'yayloyalty' ),
                            'type'        => 'integer',
                            'required'    => true,
                            'minimum'     => 1,
                        ],
                        'days'    => [
                            'description' => __( 'Days until the manual tier ends, or null for no end.', 'yayloyalty' ),
                            'type'        => [ 'integer', 'null' ],
                            'minimum'     => 1,
                            'maximum'     => 3650,
                            'default'     => null,
                        ],
                    ],
                ],
                [
                    'methods'             => \WP_REST_Server::DELETABLE,
                    'callback'            => fn( $request ) => $this->exec_write( [ $this, 'remove_tier' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::MANAGE_TIERS ),
                    'args'                => [ 'id' => $this->user_id_param() ],
                ],
            ]
        );
    }

    /** @return array<string, mixed> */
    public function get_tier( \WP_REST_Request $request ): array {
        return $this->respond( (int) $request['id'] );
    }

    /**
     * Sets a manual floor. A floor above the customer's tier raises them now and may pay its entry bonus;
     * one at or below it is stored and changes nothing else.
     *
     * @return array<string, mixed>|\WP_Error
     */
    public function assign_tier( \WP_REST_Request $request ) {
        if ( ! TierEvaluator::is_enabled() ) {
            return $this->error_tiers_disabled();
        }

        $tier_id = (int) $request['tier_id'];

        if ( null === $this->tiers->find_live( $tier_id ) ) {
            return $this->error_not_found( __( 'Tier not found.', 'yayloyalty' ) );
        }

        $user_id = (int) $request['id'];
        $days    = null === $request['days'] ? null : (int) $request['days'];

        TierEvaluator::build()->assign_floor( $user_id, $tier_id, $days, get_current_user_id() );

        return $this->respond( $user_id );
    }

    /**
     * Removes the manual floor and moves the customer to the tier their points qualify for.
     *
     * @return array<string, mixed>|\WP_Error
     */
    public function remove_tier( \WP_REST_Request $request ) {
        if ( ! TierEvaluator::is_enabled() ) {
            return $this->error_tiers_disabled();
        }

        $user_id  = (int) $request['id'];
        $customer = $this->customers->find_by_user_id( $user_id );

        if ( null === $customer || null === $customer->get_tier_manual_id() ) {
            return new \WP_Error( 'yayloyalty_no_manual_tier', __( 'This customer has no manual tier.', 'yayloyalty' ), [ 'status' => 404 ] );
        }

        TierEvaluator::build()->clear_floor( $user_id, get_current_user_id() );

        return $this->respond( $user_id );
    }

    private function error_tiers_disabled(): \WP_Error {
        return new \WP_Error( 'yayloyalty_tiers_disabled', __( 'Turn on tiers before assigning one.', 'yayloyalty' ), [ 'status' => 409 ] );
    }

    /**
     * The drawer card's shape. `tier` is null when the stored tier is deleted; `next` is the lowest live
     * tier ranked above it, or the lowest tier when the customer has none.
     *
     * @return array{tier: array<string, mixed>|null, progress_value: int, next: array<string, mixed>|null, achieved_at: string|null, manual: array<string, mixed>|null}
     */
    private function respond( int $user_id ): array {
        $customer = $this->customers->find_by_user_id( $user_id ) ?? new Customer( $user_id );
        $live     = $this->tiers->live();
        $tier     = null;

        foreach ( $live as $candidate ) {
            if ( $candidate->get_id() === $customer->get_tier_id() ) {
                $tier = $candidate;
                break;
            }
        }

        $rank = null === $tier ? 0 : $tier->get_tier_rank();
        $next = null;

        foreach ( $live as $candidate ) {
            if ( $candidate->get_tier_rank() > $rank ) {
                $next = $candidate;
                break;
            }
        }

        $progress = $customer->get_tier_progress_value();

        return [
            'tier'           => null === $tier ? null : [
                'id'        => (int) $tier->get_id(),
                'name'      => $tier->get_name(),
                'color'     => $tier->get_color(),
                'tier_rank' => $tier->get_tier_rank(),
            ],
            // The evaluator's last value; a customer never evaluated reads their points earned.
            'progress_value' => null === $progress ? $customer->get_lifetime_earned() : (int) $progress,
            'next'           => null === $next ? null : [
                'id'        => (int) $next->get_id(),
                'name'      => $next->get_name(),
                'threshold' => $next->get_threshold(),
            ],
            'achieved_at'    => $customer->get_tier_achieved_at(),
            'manual'         => $this->manual( $customer ),
        ];
    }

    /** @return array{tier_id: int, name: string, color: string, until: string|null}|null */
    private function manual( Customer $customer ): ?array {
        $floor_id = $customer->get_tier_manual_id();

        if ( null === $floor_id ) {
            return null;
        }

        $floor = $this->tiers->find_tier( $floor_id );

        return [
            'tier_id' => $floor_id,
            'name'    => $floor instanceof Tier ? $floor->get_name() : '',
            'color'   => $floor instanceof Tier ? $floor->get_color() : '',
            'until'   => $customer->get_tier_manual_until(),
        ];
    }
}
