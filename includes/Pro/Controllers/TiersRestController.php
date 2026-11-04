<?php
namespace YayLoyalty\Pro\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Controllers\AbstractRestController;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Models\Tier;
use YayLoyalty\Pro\Services\TierEvaluator;
use YayLoyalty\Pro\Services\TierSweep;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\RewardRepository;
use YayLoyalty\Repositories\TierRepository;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * The tier catalogue: list, create, edit and soft-delete. No service, as for rewards: the invariants
 * are validation, checked in full before any write. Every write first locks the live tiers, so two
 * requests can't both pass the count and uniqueness checks. Ranks are rewritten in threshold order
 * after each write. $rewards and $customers are read-only here: reference checks and member counts.
 *
 * Catalogue writes never touch a customer's tier: while tiers are on, a create, a threshold change or a
 * delete requests a re-evaluation sweep, and TierEvaluator moves customers. The switch lives here too
 * (`/tiers/settings`), behind the same capability, since `POST /settings` drops the tier keys.
 */
final class TiersRestController extends AbstractRestController {

    /** @var TierRepository */
    private $tiers;

    /** @var RewardRepository */
    private $rewards;

    /** @var CustomerRepository */
    private $customers;

    /** The fields a merchant sets; create needs all of them. */
    private const FIELDS = [ 'name', 'threshold', 'multiplier', 'entry_bonus', 'color' ];

    public function __construct( TierRepository $tiers, RewardRepository $rewards, CustomerRepository $customers ) {
        $this->tiers     = $tiers;
        $this->rewards   = $rewards;
        $this->customers = $customers;
    }

    public function get_rest_base(): string {
        return 'tiers';
    }

    public function register_routes(): void {
        // Before `/tiers/(?P<id>…)`, which can never match it, but kept first so the static route reads first.
        register_rest_route(
            self::REST_NAMESPACE,
            '/tiers/settings',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => fn( $request ) => $this->exec_read( [ $this, 'get_settings' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::MANAGE_TIERS ),
                ],
                [
                    'methods'             => \WP_REST_Server::EDITABLE,
                    'callback'            => fn( $request ) => $this->exec_write( [ $this, 'update_settings' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::MANAGE_TIERS ),
                    'args'                => [
                        'tiers_enabled' => [
                            'description' => __( 'Whether customers are placed into tiers.', 'yayloyalty' ),
                            'type'        => 'boolean',
                            'required'    => true,
                        ],
                    ],
                ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/tiers',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => fn( $request ) => $this->exec_read( [ $this, 'get_items' ], $request ),
                    // View, not manage: the customers list and the reward editor read the catalogue too.
                    'permission_callback' => $this->requires( Capabilities::VIEW ),
                ],
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => fn( $request ) => $this->exec_write( [ $this, 'create_item' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::MANAGE_TIERS ),
                    'args'                => $this->create_args(),
                ],
                'schema' => [ $this, 'get_item_schema' ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/tiers/(?P<id>[\d]+)',
            [
                [
                    'methods'             => \WP_REST_Server::EDITABLE,
                    'callback'            => fn( $request ) => $this->exec_write( [ $this, 'update_item' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::MANAGE_TIERS ),
                    'args'                => $this->get_write_args(),
                ],
                [
                    'methods'             => \WP_REST_Server::DELETABLE,
                    'callback'            => fn( $request ) => $this->exec_write( [ $this, 'delete_item' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::MANAGE_TIERS ),
                ],
                'schema' => [ $this, 'get_item_schema' ],
            ]
        );
    }

    /**
     * The tier settings, the customer count a placement would cover, and the pending sweep's progress.
     *
     * @return array{tiers_enabled: bool, tiers_mode: string, tiers_rolling_months: int, customer_count: int, sweep: array{mode: string, done: int, total: int}|null}
     */
    public function get_settings( \WP_REST_Request $request ): array {
        $settings = SettingsHelper::get_settings();

        return [
            'tiers_enabled'        => true === ( $settings['tiers_enabled'] ?? false ),
            'tiers_mode'           => (string) $settings['tiers_mode'],
            'tiers_rolling_months' => (int) $settings['tiers_rolling_months'],
            'customer_count'       => $this->customers->count_all(),
            'sweep'                => TierSweep::progress(),
        ];
    }

    /**
     * Turns tiers on or off. On places every customer through a sweep; off freezes stored tiers and drops
     * any pending sweep. Only `tiers_enabled` is writable until rolling mode exists.
     *
     * @return array<string, mixed>
     */
    public function update_settings( \WP_REST_Request $request ): array {
        $was_enabled = TierEvaluator::is_enabled();
        $enabled     = (bool) $request['tiers_enabled'];

        SettingsHelper::update_settings( [ 'tiers_enabled' => $enabled ] );

        if ( $enabled && ! $was_enabled ) {
            TierSweep::request( TierSweep::MODE_PLACEMENT );
        } elseif ( ! $enabled && $was_enabled ) {
            TierSweep::cancel();
        }

        $response = $this->get_settings( $request );

        // The sweep option is written after commit, so report the placement that is about to start.
        if ( $enabled && ! $was_enabled ) {
            $response['sweep'] = [
                'mode'  => TierSweep::MODE_PLACEMENT,
                'done'  => 0,
                'total' => $response['customer_count'],
            ];
        }

        return $response;
    }

    /** @return array<int, array<string, mixed>> Live tiers, lowest threshold first. */
    public function get_items( \WP_REST_Request $request ): array {
        $counts = $this->customers->count_by_tier();

        return array_map(
            fn( Tier $tier ) => $this->respond_item( $tier, $counts ),
            $this->tiers->live()
        );
    }

    /** @return array<string, mixed>|\WP_Error */
    public function create_item( \WP_REST_Request $request ) {
        $this->tiers->lock_live();

        $fields = $this->read_fields( $request );

        if ( $fields instanceof \WP_Error ) {
            return $fields;
        }

        $live = $this->tiers->live();

        if ( count( $live ) >= Tier::MAX_LIVE ) {
            return new \WP_Error(
                'yayloyalty_tier_limit',
                sprintf(
                    /* translators: %s: the most tiers a store may have. */
                    __( 'You can have at most %s tiers.', 'yayloyalty' ),
                    SettingsHelper::format_number( Tier::MAX_LIVE )
                ),
                [ 'status' => 409 ]
            );
        }

        $error = $this->uniqueness_error( $live, $fields['name'], $fields['threshold'], null );

        if ( null !== $error ) {
            return $error;
        }

        $now = Dates::now();
        $id  = $this->tiers->insert(
            new Tier( null, $fields['name'], $fields['threshold'], 0, $fields['multiplier'], $fields['entry_bonus'], $fields['color'], $now, $now, null )
        );

        $this->tiers->rerank();
        $this->request_reevaluation();

        return $this->respond_saved( $id );
    }

    /** @return array<string, mixed>|\WP_Error */
    public function update_item( \WP_REST_Request $request ) {
        $this->tiers->lock_live();

        $tier = $this->tiers->find_live( (int) $request['id'] );

        if ( null === $tier ) {
            return $this->error_not_found( __( 'Tier not found.', 'yayloyalty' ) );
        }

        $fields = $this->read_fields( $request, $tier );

        if ( $fields instanceof \WP_Error ) {
            return $fields;
        }

        $error = $this->uniqueness_error( $this->tiers->live(), $fields['name'], $fields['threshold'], (int) $tier->get_id() );

        if ( null !== $error ) {
            return $error;
        }

        $threshold_changed = $fields['threshold'] !== $tier->get_threshold();

        $tier->set_name( $fields['name'] );
        $tier->set_threshold( $fields['threshold'] );
        $tier->set_multiplier( $fields['multiplier'] );
        $tier->set_entry_bonus( $fields['entry_bonus'] );
        $tier->set_color( $fields['color'] );
        $tier->set_updated_at( Dates::now() );

        $row = $tier->to_update_row();
        $this->tiers->update( (int) $tier->get_id(), $row['data'], $row['format'] );
        $this->tiers->rerank();

        if ( $threshold_changed ) {
            $this->request_reevaluation();
        }

        return $this->respond_saved( (int) $tier->get_id() );
    }

    /**
     * Soft-deletes a tier, so activity history keeps its name. Refused for the last live tier and
     * for a tier a reward's conditions still name, whatever that reward's status.
     *
     * @return array{deleted: bool, id: int}|\WP_Error
     */
    public function delete_item( \WP_REST_Request $request ) {
        $this->tiers->lock_live();

        $tier = $this->tiers->find_live( (int) $request['id'] );

        if ( null === $tier ) {
            return $this->error_not_found( __( 'Tier not found.', 'yayloyalty' ) );
        }

        if ( count( $this->tiers->live() ) <= 1 ) {
            return new \WP_Error( 'yayloyalty_last_tier', __( 'You need at least one tier.', 'yayloyalty' ), [ 'status' => 409 ] );
        }

        $id          = (int) $tier->get_id();
        $referencing = $this->rewards->find_referencing_tier( $id );

        if ( [] !== $referencing ) {
            $rewards = array_map(
                static function ( $reward ): array {
                    return [
                        'id'     => (int) $reward->get_id(),
                        'name'   => $reward->get_name(),
                        'status' => $reward->get_status(),
                    ];
                },
                $referencing
            );

            return new \WP_Error(
                'yayloyalty_tier_in_use',
                __( 'Rewards require this tier. Remove the tier from them before deleting it.', 'yayloyalty' ),
                [
                    'status'  => 409,
                    'rewards' => $rewards,
                ]
            );
        }

        $this->tiers->soft_delete( $id, Dates::now() );
        $this->tiers->rerank();
        $this->request_reevaluation();

        return [
            'deleted' => true,
            'id'      => $id,
        ];
    }

    /** While tiers are on, asks for every customer to be evaluated against the changed catalogue. */
    private function request_reevaluation(): void {
        if ( TierEvaluator::is_enabled() ) {
            TierSweep::request( TierSweep::MODE_REEVALUATE );
        }
    }

    /**
     * The merchant-set fields, normalized: those the request sends, else $stored's. Create passes no
     * $stored, so every field must be sent. The first invalid field refuses the request.
     *
     * @return array{name: string, threshold: int, multiplier: string, entry_bonus: int, color: string}|\WP_Error
     */
    private function read_fields( \WP_REST_Request $request, ?Tier $stored = null ) {
        $current = null === $stored ? [] : [
            'name'        => $stored->get_name(),
            'threshold'   => $stored->get_threshold(),
            'multiplier'  => $stored->get_multiplier(),
            'entry_bonus' => $stored->get_entry_bonus(),
            'color'       => $stored->get_color(),
        ];
        $fields  = [];

        foreach ( self::FIELDS as $field ) {
            if ( ! $request->has_param( $field ) ) {
                if ( ! array_key_exists( $field, $current ) ) {
                    return $this->field_error( $field );
                }

                $fields[ $field ] = $current[ $field ];
                continue;
            }

            $value = $this->normalize_field( $field, $request->get_param( $field ) );

            if ( null === $value ) {
                return $this->field_error( $field );
            }

            $fields[ $field ] = $value;
        }

        return $fields;
    }

    /**
     * Tier::normalize_<field>() for one of FIELDS.
     *
     * @param mixed $value
     * @return string|int|null
     */
    private function normalize_field( string $field, $value ) {
        return call_user_func( [ Tier::class, 'normalize_' . $field ], $value );
    }

    private function field_error( string $field ): \WP_Error {
        switch ( $field ) {
            case 'name':
                $message = sprintf(
                    /* translators: %s: the longest tier name, in characters. */
                    __( 'A tier needs a name of up to %s characters.', 'yayloyalty' ),
                    SettingsHelper::format_number( Tier::NAME_MAX )
                );
                break;

            case 'threshold':
                $message = sprintf(
                    /* translators: %s: the highest number of points a tier may start at. */
                    __( 'The points to reach a tier must be a whole number from 0 to %s.', 'yayloyalty' ),
                    SettingsHelper::format_number( Tier::THRESHOLD_MAX )
                );
                break;

            case 'multiplier':
                $message = sprintf(
                    /* translators: 1: the lowest multiplier, 2: the highest multiplier. */
                    __( 'The multiplier must be from %1$s to %2$s, in steps of 0.05.', 'yayloyalty' ),
                    SettingsHelper::format_number( (float) Tier::MULTIPLIER_MIN, 2 ),
                    SettingsHelper::format_number( (float) Tier::MULTIPLIER_MAX, 2 )
                );
                break;

            case 'entry_bonus':
                $message = sprintf(
                    /* translators: %s: the largest entry bonus, in points. */
                    __( 'The entry bonus must be a whole number of points from 0 to %s.', 'yayloyalty' ),
                    SettingsHelper::format_number( Tier::ENTRY_BONUS_MAX )
                );
                break;

            default:
                $message = __( 'The colour must be a hex colour such as #C0C0C0.', 'yayloyalty' );
        }

        return $this->error_invalid_arguments( $message );
    }

    /**
     * Why $name or $threshold collides with another live tier, or null. Names compare case-insensitively.
     *
     * @param Tier[] $live
     */
    private function uniqueness_error( array $live, string $name, int $threshold, ?int $except_id ): ?\WP_Error {
        $folded = mb_strtolower( $name );

        foreach ( $live as $tier ) {
            if ( $except_id === $tier->get_id() ) {
                continue;
            }

            if ( mb_strtolower( $tier->get_name() ) === $folded ) {
                return $this->error_invalid_arguments( __( 'A tier with that name already exists.', 'yayloyalty' ) );
            }

            if ( $tier->get_threshold() === $threshold ) {
                return $this->error_invalid_arguments( __( 'Another tier already starts at this number of points.', 'yayloyalty' ) );
            }
        }

        return null;
    }

    /** @return array<string, mixed> The tier as stored after a write, its new rank included. */
    private function respond_saved( int $id ): array {
        $tier = $this->tiers->find_tier( $id );

        return null === $tier ? [] : $this->respond_item( $tier, $this->customers->count_by_tier() );
    }

    /**
     * @param array<int, int> $counts Tier id => members.
     * @return array<string, mixed>
     */
    private function respond_item( Tier $tier, array $counts ): array {
        $item                 = $tier->to_array();
        $item['member_count'] = $counts[ (int) $tier->get_id() ] ?? 0;

        return $item;
    }

    /**
     * Create's args: the write args with every merchant-set field required.
     *
     * @return array<string, mixed>
     */
    private function create_args(): array {
        $args = $this->get_write_args();

        foreach ( self::FIELDS as $field ) {
            $args[ $field ]['required'] = true;
        }

        return $args;
    }

    /** @return array<string, mixed> */
    protected function define_item_schema(): array {
        return [
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'yayloyalty_tier',
            'type'       => 'object',
            'properties' => [
                'id'           => [
                    'type'     => 'integer',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'name'         => [
                    'type'    => 'string',
                    'context' => [ 'view', 'edit' ],
                ],
                'threshold'    => [
                    'description' => __( 'Points earned that qualify for the tier.', 'yayloyalty' ),
                    'type'        => 'integer',
                    'context'     => [ 'view', 'edit' ],
                ],
                'tier_rank'    => [
                    'description' => __( '1 for the lowest live tier, in threshold order.', 'yayloyalty' ),
                    'type'        => 'integer',
                    'context'     => [ 'view' ],
                    'readonly'    => true,
                ],
                'multiplier'   => [
                    'description' => __( 'Points multiplier as a decimal string, 1.00 to 10.00 in steps of 0.05.', 'yayloyalty' ),
                    'type'        => [ 'string', 'number' ],
                    'context'     => [ 'view', 'edit' ],
                ],
                'entry_bonus'  => [
                    'description' => __( 'Points paid once on reaching the tier.', 'yayloyalty' ),
                    'type'        => 'integer',
                    'context'     => [ 'view', 'edit' ],
                ],
                'color'        => [
                    'description' => __( 'Badge colour, #RRGGBB.', 'yayloyalty' ),
                    'type'        => 'string',
                    'context'     => [ 'view', 'edit' ],
                ],
                'member_count' => [
                    'type'     => 'integer',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'created_at'   => [
                    'type'     => 'string',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'updated_at'   => [
                    'type'     => 'string',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
            ],
        ];
    }
}
