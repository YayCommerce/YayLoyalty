<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\Status;
use YayLoyalty\Models\Campaign;
use YayLoyalty\Models\CampaignConditionSet;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Campaigns CRUD; the template for other definition tables. No service: the
 * invariants are validation. Built-in protection relies on is_builtin being
 * readonly in the schema, never on reading it from a request.
 *
 * Reads answer from dummy_fixture() for now: a fresh table holds only the
 * three built-ins, too few to build the Pro grouped table against.
 * create_item()/delete_item() already go through $this->campaigns.
 * update_item() resolves and returns the fixture row without writing: a
 * real write would vanish behind the fixture on the next read, and fixture
 * ids 1-3 collide with real built-in ids. Swapping the reads back restores
 * update_item()'s write and deletes dummy_fixture()/find_dummy_row().
 */
final class CampaignRestController extends AbstractRestController {

    /** @var CampaignRepository */
    private $campaigns;

    public function __construct( CampaignRepository $campaigns ) {
        $this->campaigns = $campaigns;
    }

    public function get_rest_base(): string {
        return 'campaigns';
    }

    public function register_routes(): void {
        register_rest_route(
            self::REST_NAMESPACE,
            '/campaigns',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => fn( $request ) => $this->exec_read( [ $this, 'get_items' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::MANAGE_CAMPAIGNS ),
                    'args'                => array_merge(
                        $this->get_collection_params(),
                        [
                            'trigger' => [
                                'type' => 'string',
                                'enum' => CampaignTrigger::all(),
                            ],
                            'status'  => [
                                'type' => 'string',
                                'enum' => Status::all(),
                            ],
                        ]
                    ),
                ],
                [
                    'methods'             => \WP_REST_Server::CREATABLE,
                    'callback'            => fn( $request ) => $this->exec_write( [ $this, 'create_item' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::MANAGE_CAMPAIGNS ),
                    'args'                => $this->get_write_args(),
                ],
                'schema' => [ $this, 'get_item_schema' ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/campaigns/(?P<id>[\d]+)',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => fn( $request ) => $this->exec_read( [ $this, 'get_item' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::MANAGE_CAMPAIGNS ),
                ],
                [
                    'methods'             => \WP_REST_Server::EDITABLE,
                    'callback'            => fn( $request ) => $this->exec_write( [ $this, 'update_item' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::MANAGE_CAMPAIGNS ),
                    'args'                => $this->get_write_args(),
                ],
                [
                    'methods'             => \WP_REST_Server::DELETABLE,
                    'callback'            => fn( $request ) => $this->exec_write( [ $this, 'delete_item' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::MANAGE_CAMPAIGNS ),
                ],
                'schema' => [ $this, 'get_item_schema' ],
            ]
        );
    }

    public function get_items( \WP_REST_Request $request ) {
        $result = $this->filter_dummy_fixture(
            [
                'page'     => (int) $request['page'],
                'per_page' => (int) $request['per_page'],
                'orderby'  => $request['orderby'] ? (string) $request['orderby'] : 'priority',
                'order'    => $request['order'] ? (string) $request['order'] : 'desc',
                'trigger'  => $request['trigger'] ? (string) $request['trigger'] : '',
                'status'   => $request['status'] ? (string) $request['status'] : '',
                'search'   => $request['search'] ? (string) $request['search'] : '',
            ]
        );

        return $this->respond_paginated( $result );
    }

    public function get_item( \WP_REST_Request $request ) {
        $campaign = self::find_dummy_row( (int) $request['id'] );

        if ( null === $campaign ) {
            return $this->error_not_found( __( 'Campaign not found.', 'yayloyalty' ) );
        }

        return $campaign;
    }

    public function create_item( \WP_REST_Request $request ) {
        $trigger = (string) $request['trigger'];

        if ( ! CampaignTrigger::is_valid( $trigger ) ) {
            return $this->error_invalid_arguments( __( 'Unknown campaign trigger.', 'yayloyalty' ) );
        }

        $name = trim( (string) $request['name'] );

        if ( '' === $name ) {
            return $this->error_invalid_arguments( __( 'A campaign needs a name.', 'yayloyalty' ) );
        }

        $config     = (array) ( $request['config'] ?? [] );
        $conditions = (array) ( $request['conditions'] ?? [] );

        if ( ! Campaign::config_is_valid( $trigger, $config ) ) {
            return $this->error_invalid_arguments( __( 'The campaign config has an unknown key or a value of the wrong type.', 'yayloyalty' ) );
        }

        if ( ! Campaign::conditions_are_valid( $conditions ) ) {
            return $this->error_invalid_arguments( __( 'The campaign conditions are malformed.', 'yayloyalty' ) );
        }

        // A client slug is a starting point, never trusted to be unique.
        $slug = $this->unique_slug( $request['slug'] ? (string) $request['slug'] : $name );

        $now = Dates::now();

        $campaign = new Campaign(
            null,
            $name,
            $slug,
            $trigger,
            $request['status'] ? Status::assert( (string) $request['status'] ) : Status::ACTIVE,
            null !== $request['priority'] ? (int) $request['priority'] : 0,
            Campaign::validate_config( $trigger, $config ),
            CampaignConditionSet::normalize( $conditions ),
            $this->nullable_date( $request['starts_at'] ),
            $this->nullable_date( $request['ends_at'] ),
            false, // is_builtin is never settable from a request.
            get_current_user_id(),
            $now,
            $now
        );

        $id = $this->campaigns->insert( $campaign );

        $created = $this->campaigns->find_campaign( $id );

        return null === $created ? $campaign->to_array() : $created->to_array();
    }

    public function update_item( \WP_REST_Request $request ) {
        // Fixture only, no repository fallback: fixture ids 1-3 collide with real built-in ids.
        $row = self::find_dummy_row( (int) $request['id'] );

        if ( null === $row ) {
            return $this->error_not_found( __( 'Campaign not found.', 'yayloyalty' ) );
        }

        // The fixture row is the REST shape (decoded config/conditions), which from_row() cannot take.
        $campaign = new Campaign(
            $row['id'],
            $row['name'],
            $row['slug'],
            $row['trigger'],
            $row['status'],
            $row['priority'],
            $row['config'],
            $row['conditions'],
            $row['starts_at'],
            $row['ends_at'],
            $row['is_builtin'],
            $row['created_by'],
            $row['created_at'],
            $row['updated_at']
        );

        // Validate before any setter runs so a rejected request leaves the model untouched.
        if ( null !== $request['config'] && ! Campaign::config_is_valid( $campaign->get_trigger(), (array) $request['config'] ) ) {
            return $this->error_invalid_arguments( __( 'The campaign config has an unknown key or a value of the wrong type.', 'yayloyalty' ) );
        }

        if ( null !== $request['conditions'] && ! Campaign::conditions_are_valid( (array) $request['conditions'] ) ) {
            return $this->error_invalid_arguments( __( 'The campaign conditions are malformed.', 'yayloyalty' ) );
        }

        // slug and trigger are immutable after create: built-ins are addressed by slug across sites.
        if ( null !== $request['name'] ) {
            $campaign->set_name( trim( (string) $request['name'] ) );
        }

        if ( null !== $request['status'] ) {
            $campaign->set_status( (string) $request['status'] );
        }

        if ( null !== $request['priority'] ) {
            $campaign->set_priority( (int) $request['priority'] );
        }

        if ( null !== $request['config'] ) {
            $campaign->set_config( (array) $request['config'] );
        }

        if ( null !== $request['conditions'] ) {
            $campaign->set_conditions( (array) $request['conditions'] );
        }

        if ( $request->has_param( 'starts_at' ) ) {
            $campaign->set_starts_at( $this->nullable_date( $request['starts_at'] ) );
        }

        if ( $request->has_param( 'ends_at' ) ) {
            $campaign->set_ends_at( $this->nullable_date( $request['ends_at'] ) );
        }

        $campaign->set_updated_at( Dates::now() );
        $campaign->set_updated_by( get_current_user_id() );

        // Swap point: no $this->campaigns->update() call; see the class docblock. When restored,
        // strip slug/trigger_type/created_at/created_by/is_builtin from to_row() before writing.
        return $campaign->to_array();
    }

    public function delete_item( \WP_REST_Request $request ) {
        $campaign = $this->campaigns->find_campaign( (int) $request['id'] );

        if ( null === $campaign ) {
            return $this->error_not_found( __( 'Campaign not found.', 'yayloyalty' ) );
        }

        // The engine and the Free UI expect built-ins to exist. is_builtin is readonly, so this cannot be sidestepped.
        if ( $campaign->is_builtin() ) {
            return $this->error_forbidden( __( 'Built-in campaigns cannot be deleted.', 'yayloyalty' ) );
        }

        $this->campaigns->delete( (int) $request['id'] );

        return [
            'deleted' => true,
            'id'      => (int) $request['id'],
        ];
    }

    /** A slug unique in the table, derived from $source. */
    private function unique_slug( string $source ): string {
        $base = sanitize_title( $source );

        if ( '' === $base ) {
            $base = 'campaign';
        }

        $base = substr( $base, 0, 60 );
        $slug = $base;
        $n    = 2;

        while ( null !== $this->campaigns->find_by_slug( $slug ) ) {
            $slug = substr( $base, 0, 60 - strlen( (string) $n ) - 1 ) . '-' . $n;
            ++$n;
        }

        return $slug;
    }

    /**
     * One row of dummy_fixture(), by id.
     *
     * @return array<string, mixed>|null
     */
    public static function find_dummy_row( int $id ): ?array {
        foreach ( self::dummy_fixture() as $row ) {
            if ( $row['id'] === $id ) {
                return $row;
            }
        }

        return null;
    }

    /**
     * dummy_fixture() filtered, sorted and paged with the same params and
     * shape as CampaignRepository::paginate_filtered(), so the swap back is one call.
     *
     * @param array{page:int,per_page:int,orderby:string,order:string,trigger:string,status:string,search:string} $args
     * @return array{items: mixed[], total: int, page: int, per_page: int}
     */
    private function filter_dummy_fixture( array $args ): array {
        $rows = self::dummy_fixture();

        if ( '' !== $args['trigger'] ) {
            $rows = array_values( array_filter( $rows, static fn( $row ) => $row['trigger'] === $args['trigger'] ) );
        }

        if ( '' !== $args['status'] ) {
            $rows = array_values( array_filter( $rows, static fn( $row ) => $row['status'] === $args['status'] ) );
        }

        if ( '' !== $args['search'] ) {
            $needle = strtolower( $args['search'] );
            $rows   = array_values(
                array_filter( $rows, static fn( $row ) => false !== stripos( $row['name'], $needle ) )
            );
        }

        $orderby = in_array( $args['orderby'], [ 'id', 'name', 'priority', 'status', 'created_at', 'updated_at' ], true )
            ? $args['orderby']
            : 'priority';
        $desc    = 'asc' !== strtolower( $args['order'] );

        usort(
            $rows,
            static function ( $a, $b ) use ( $orderby, $desc ) {
                // Ties go to the newest, as live campaign selection does (data-model/campaigns.md).
                $cmp = $a[ $orderby ] <=> $b[ $orderby ];
                $cmp = $desc ? -$cmp : $cmp;

                return 0 !== $cmp ? $cmp : ( $b['id'] <=> $a['id'] );
            }
        );

        $total    = count( $rows );
        $per_page = max( 1, $args['per_page'] );
        $page     = max( 1, $args['page'] );

        return [
            'items'    => array_slice( $rows, ( $page - 1 ) * $per_page, $per_page ),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        ];
    }

    /**
     * Twenty-two campaigns: every fireable trigger, an archived row, a
     * priority tie (ids 3 and 9), enough to cross the Pro table's search
     * threshold. ids 4-6 must match Installer::seed_builtins() exactly: they
     * are the rows BuiltInCampaignList edits. `conditions` stays `[]` where
     * the natural fit is `tier`, which validation rejects until tiers exist.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function dummy_fixture(): array {
        $purchase_defaults = Campaign::default_config( CampaignTrigger::PURCHASE );

        $multiplier = static fn( float $multiplier ) => array_merge(
            $purchase_defaults,
            [
                'mode'                 => 'multiplier',
                'multiplier'           => $multiplier,
                'max_points_per_order' => 0,
                'daily_cap'            => 0,
            ]
        );

        $rate = static fn( float $points_per_unit ) => array_merge(
            $purchase_defaults,
            [
                'mode'                 => 'rate',
                'points_per_unit'      => $points_per_unit,
                'max_points_per_order' => 0,
                'daily_cap'            => 0,
            ]
        );

        // Multi-key arrays are named here so each campaign below fits one line under WPCS.
        $now = [
            'created_at' => '2026-06-01 00:00:00',
            'updated_at' => '2026-09-01 00:00:00',
        ];

        $trail_shoes_conditions = [
            [
                'type'     => 'category_in_cart',
                'operator' => 'in',
                'value'    => [ 123 ],
            ],
        ];
        $clearance_conditions   = [
            [
                'type'     => 'category_in_cart',
                'operator' => 'in',
                'value'    => [ 456 ],
            ],
        ];

        $birthday_points_config   = [
            'points'      => 500,
            'days_before' => 0,
        ];
        $welcome_bonus_config     = [ 'points' => 50 ];
        $referral_signup_config   = [ 'points' => 25 ];
        $photo_review_config      = [
            'points'             => 20,
            'require_verified'   => false,
            'one_per_product'    => false,
            'daily_cap'          => 0,
            'media_bonus_points' => 0,
        ];
        $video_review_config      = [
            'points'             => 75,
            'require_verified'   => true,
            'one_per_product'    => true,
            'daily_cap'          => 0,
            'media_bonus_points' => 0,
        ];
        $first_order_bonus_config = [
            'points'       => 150,
            'min_subtotal' => 0,
        ];
        $frequent_buyer_config    = [
            'points'      => 200,
            'order_count' => 5,
            'repeat'      => false,
        ];
        $big_spender_config       = [
            'points'       => 1000,
            'spend_amount' => 500,
            'repeat'       => false,
        ];
        $anniversary_bonus_config = [
            'points'      => 300,
            'days_before' => 0,
        ];
        $tier_upgrade_config      = [
            'points'       => 500,
            'spend_amount' => 1000,
            'repeat'       => false,
        ];

        return [
            self::dummy_row( 1, 'Summit+ members 1.5×', 'summit-plus-members-1-5x', CampaignTrigger::PURCHASE, Status::ACTIVE, 100, $multiplier( 1.5 ), [], null, null, false, $now ),
            self::dummy_row( 2, 'Black Friday 2×', 'black-friday-2x', CampaignTrigger::PURCHASE, Status::ACTIVE, 90, $multiplier( 2 ), [], '2026-11-27 00:00:00', '2026-12-01 00:00:00', false, $now ),
            self::dummy_row( 3, 'Trail Shoes boost', 'trail-shoes-boost', CampaignTrigger::PURCHASE, Status::PAUSED, 80, $rate( 3 ), $trail_shoes_conditions, null, null, false, $now ),
            self::dummy_row( 4, 'Points for purchases', CampaignRepository::DEFAULT_PURCHASE_SLUG, CampaignTrigger::PURCHASE, Status::ACTIVE, 0, $purchase_defaults, [], null, null, true, $now ),
            self::dummy_row( 5, 'Points for signing up', 'signup', CampaignTrigger::SIGNUP, Status::ACTIVE, 0, Campaign::default_config( CampaignTrigger::SIGNUP ), [], null, null, true, $now ),
            self::dummy_row( 6, 'Points for writing a review', 'review', CampaignTrigger::REVIEW, Status::ACTIVE, 0, Campaign::default_config( CampaignTrigger::REVIEW ), [], null, null, true, $now ),
            self::dummy_row( 7, 'Birthday points', 'birthday-points', CampaignTrigger::BIRTHDAY, Status::DRAFT, 0, $birthday_points_config, [], null, null, false, $now ),
            self::dummy_row( 8, 'Spring Sale 1.5×', 'spring-sale-1-5x', CampaignTrigger::PURCHASE, Status::ARCHIVED, 5, $multiplier( 1.5 ), [], '2026-03-01 00:00:00', '2026-03-16 00:00:00', false, $now ),
            self::dummy_row( 9, 'VIP double points', 'vip-double-points', CampaignTrigger::PURCHASE, Status::ACTIVE, 80, $multiplier( 2 ), [], null, null, false, $now ),
            self::dummy_row( 10, 'Loyalty week 1.2×', 'loyalty-week-1-2x', CampaignTrigger::PURCHASE, Status::ACTIVE, 35, $multiplier( 1.2 ), [], '2026-12-01 00:00:00', '2026-12-08 00:00:00', false, $now ),
            self::dummy_row( 11, 'Newsletter bonus', 'newsletter-bonus', CampaignTrigger::PURCHASE, Status::ACTIVE, 25, $rate( 2 ), [], null, null, false, $now ),
            self::dummy_row( 12, 'Weekend flash 1.3×', 'weekend-flash-1-3x', CampaignTrigger::PURCHASE, Status::PAUSED, 15, $multiplier( 1.3 ), [], null, null, false, $now ),
            self::dummy_row( 13, 'Clearance category boost', 'clearance-category-boost', CampaignTrigger::PURCHASE, Status::DRAFT, 5, $rate( 2 ), $clearance_conditions, null, null, false, $now ),
            self::dummy_row( 14, 'Welcome bonus boost', 'welcome-bonus-boost', CampaignTrigger::SIGNUP, Status::ACTIVE, 10, $welcome_bonus_config, [], null, null, false, $now ),
            self::dummy_row( 15, 'Referral signup bonus', 'referral-signup-bonus', CampaignTrigger::SIGNUP, Status::ACTIVE, 5, $referral_signup_config, [], null, null, false, $now ),
            self::dummy_row( 16, 'Photo review bonus', 'photo-review-bonus', CampaignTrigger::REVIEW, Status::ACTIVE, 10, $photo_review_config, [], null, null, false, $now ),
            self::dummy_row( 17, 'Video review bonus', 'video-review-bonus', CampaignTrigger::REVIEW, Status::PAUSED, 5, $video_review_config, [], null, null, false, $now ),
            self::dummy_row( 18, 'First order bonus', 'first-order-bonus', CampaignTrigger::FIRST_ORDER, Status::ACTIVE, 5, $first_order_bonus_config, [], null, null, false, $now ),
            self::dummy_row( 19, 'Frequent buyer reward', 'frequent-buyer-reward', CampaignTrigger::ORDER_COUNT, Status::ACTIVE, 10, $frequent_buyer_config, [], null, null, false, $now ),
            self::dummy_row( 20, 'Big spender milestone', 'big-spender-milestone', CampaignTrigger::SPEND_MILESTONE, Status::ACTIVE, 15, $big_spender_config, [], '2027-01-01 00:00:00', null, false, $now ),
            self::dummy_row( 21, 'Anniversary bonus', 'anniversary-bonus', CampaignTrigger::BIRTHDAY, Status::DRAFT, 20, $anniversary_bonus_config, [], null, null, false, $now ),
            self::dummy_row( 22, 'Tier upgrade bonus', 'tier-upgrade-bonus', CampaignTrigger::SPEND_MILESTONE, Status::ACTIVE, 25, $tier_upgrade_config, [], null, null, false, $now ),
        ];
    }

    /**
     * @param array<string, mixed>  $config
     * @param array<int, mixed>     $conditions
     * @param array{created_at:string,updated_at:string} $timestamps
     * @return array<string, mixed>
     */
    private static function dummy_row(
        int $id,
        string $name,
        string $slug,
        string $trigger,
        string $status,
        int $priority,
        array $config,
        array $conditions,
        ?string $starts_at,
        ?string $ends_at,
        bool $is_builtin,
        array $timestamps
    ): array {
        return [
            'id'         => $id,
            'name'       => $name,
            'slug'       => $slug,
            'trigger'    => $trigger,
            'status'     => $status,
            'priority'   => $priority,
            'config'     => $config,
            'conditions' => $conditions,
            'starts_at'  => $starts_at,
            'ends_at'    => $ends_at,
            'is_builtin' => $is_builtin,
            'created_by' => null,
            'created_at' => $timestamps['created_at'],
            'updated_at' => $timestamps['updated_at'],
        ];
    }

    /**
     * @param mixed $value
     */
    private function nullable_date( $value ): ?string {
        if ( null === $value || '' === $value ) {
            return null;
        }

        return (string) $value;
    }

    protected function define_item_schema(): array {
        return [
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'yayloyalty_campaign',
            'type'       => 'object',
            'properties' => [
                'id'         => [
                    'type'     => 'integer',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'name'       => [
                    'type'    => 'string',
                    'context' => [ 'view', 'edit' ],
                ],
                'slug'       => [
                    'description' => __( 'Stable machine key. Generated on create and immutable afterwards.', 'yayloyalty' ),
                    'type'        => 'string',
                    'context'     => [ 'view', 'edit' ],
                ],
                'trigger'    => [
                    'type'    => 'string',
                    'enum'    => CampaignTrigger::all(),
                    'context' => [ 'view', 'edit' ],
                ],
                'status'     => [
                    'description' => __( 'Merchant intent only. Liveness is derived from the dates.', 'yayloyalty' ),
                    'type'        => 'string',
                    'enum'        => Status::all(),
                    'context'     => [ 'view', 'edit' ],
                ],
                'priority'   => [
                    'type'    => 'integer',
                    'context' => [ 'view', 'edit' ],
                ],
                'config'     => [
                    'type'    => 'object',
                    'context' => [ 'view', 'edit' ],
                ],
                'conditions' => [
                    'type'    => [ 'object', 'array' ],
                    'context' => [ 'view', 'edit' ],
                ],
                'starts_at'  => [
                    'type'    => [ 'string', 'null' ],
                    'context' => [ 'view', 'edit' ],
                ],
                'ends_at'    => [
                    'description' => __( 'Exclusive: a campaign is not live at exactly this instant.', 'yayloyalty' ),
                    'type'        => [ 'string', 'null' ],
                    'context'     => [ 'view', 'edit' ],
                ],
                'is_builtin' => [
                    'type'     => 'boolean',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'created_by' => [
                    'type'     => [ 'integer', 'null' ],
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'created_at' => [
                    'type'     => 'string',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
                'updated_at' => [
                    'type'     => 'string',
                    'context'  => [ 'view' ],
                    'readonly' => true,
                ],
            ],
        ];
    }
}
