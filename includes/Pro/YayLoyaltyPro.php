<?php
namespace YayLoyalty\Pro;

use YayLoyalty\Engine\Earning\EarningService;
use YayLoyalty\Engine\Points\PointsEngine;
use YayLoyalty\Engine\Rewards\Handlers\Registry;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Pro\Controllers\CampaignPerformanceRestController;
use YayLoyalty\Pro\Controllers\CampaignPreviewRestController;
use YayLoyalty\Pro\Controllers\CustomerSegmentsRestController;
use YayLoyalty\Pro\Controllers\DashboardProRestController;
use YayLoyalty\Pro\Controllers\StoreLedgerRestController;
use YayLoyalty\Pro\Emails\ProEmails;
use YayLoyalty\Pro\Helpers\CustomerSegmentsHelper;
use YayLoyalty\Pro\Repositories\CampaignPerformanceRepository;
use YayLoyalty\Pro\Repositories\CampaignPointsRepository;
use YayLoyalty\Pro\Repositories\StoreLedgerRepository;
use YayLoyalty\Pro\Services\ConditionsEvaluator;
use YayLoyalty\Pro\Services\DailyEarningLimit;
use YayLoyalty\Pro\Services\DiscountRewardHandler;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Repositories\DashboardRepository;
use YayLoyalty\Services\Dashboard\DashboardCache;

defined( 'ABSPATH' ) || exit;

/**
 * Pro entry point: wiring only. The Lite build deletes includes/Pro/, and the only reference from outside
 * is the guarded boot() call in YayLoyalty. Pro reaches Lite only through the filters boot() attaches:
 * add a seam, never a strip marker.
 */
final class YayLoyaltyPro {

    /** Guards against boot() running twice. */
    private static $booted = false;

    /** @var ConditionsEvaluator|null */
    private static $evaluator = null;

    /**
     * Triggers Pro offers. referral, social and renewal are recognised but not
     * yet fireable, so offering them would sell a switch that does nothing.
     *
     * @var string[]
     */
    private const ADDED_TRIGGERS = [
        CampaignTrigger::BIRTHDAY,
        CampaignTrigger::FIRST_ORDER,
        CampaignTrigger::ORDER_COUNT,
        CampaignTrigger::SPEND_MILESTONE,
    ];

    /** @var string[] */
    private const ADDED_REWARD_TYPES = [
        RewardType::FIXED_COUPON,
        RewardType::PERCENT_COUPON,
        RewardType::FREE_SHIPPING,
        RewardType::FREE_PRODUCT,
    ];

    /**
     * Attaches Pro to Lite's seams. Runs on plugins_loaded, before
     * rest_api_init, so the trigger and schema filters precede route registration.
     */
    public static function boot(): void {
        if ( self::$booted ) {
            return;
        }

        self::$booted = true;

        add_filter( 'yayloyalty_campaign_triggers', [ self::class, 'add_triggers' ] );
        add_filter( 'yayloyalty_campaign_config_whitelist', [ self::class, 'add_config_keys' ] );
        add_filter( 'yayloyalty_reward_types', [ self::class, 'add_reward_types' ] );
        add_filter( 'yayloyalty_reward_config_schema', [ self::class, 'add_reward_config_schema' ] );
        add_action( 'yayloyalty_register_reward_handlers', [ self::class, 'register_reward_handlers' ] );
        add_filter( PointsEngine::CONDITIONS_EVALUATOR_FILTER, [ self::class, 'conditions_evaluator' ] );
        add_filter( 'yayloyalty_rest_controllers', [ self::class, 'add_controllers' ] );
        add_filter( 'yayloyalty_rest_item_schema', [ self::class, 'describe_pro_config' ], 10, 2 );
        add_filter( 'yayloyalty_localize_admin_data', [ self::class, 'add_segments' ] );
        add_filter( 'woocommerce_email_classes', [ ProEmails::class, 'add_classes' ] );
        add_filter( 'woocommerce_email_groups', [ ProEmails::class, 'add_groups' ] );

        $daily_limit = new DailyEarningLimit( new LedgerRepository() );
        add_filter( EarningService::AWARD_POINTS_FILTER, [ $daily_limit, 'clamp' ], 10, 3 );
        add_filter( EarningService::POINTS_LEFT_TODAY_FILTER, [ $daily_limit, 'left_today' ], 10, 2 );

        /** Fires once Pro has attached its hooks. Pro-internal; absent from Lite. */
        do_action( 'yayloyalty_pro_booted' );
    }

    /** Whether boot() has run; YayLoyalty::is_pro() only asks whether the code is present. */
    public static function is_booted(): bool {
        return self::$booted;
    }

    /**
     * Widens the triggers this build offers; all_known() is unfiltered so a downgraded site still recognises them.
     *
     * @param string[] $triggers
     * @return string[]
     */
    public static function add_triggers( array $triggers ): array {
        return array_values( array_unique( array_merge( $triggers, self::ADDED_TRIGGERS ) ) );
    }

    /**
     * Config keys for the triggers Pro adds. The purchase capping keys are
     * not here: Lite whitelists them so a downgraded site keeps them.
     *
     * @param array<string, string[]> $whitelist
     * @return array<string, string[]>
     */
    public static function add_config_keys( array $whitelist ): array {
        $whitelist[ CampaignTrigger::BIRTHDAY ] = [
            'points',
            'days_before',
        ];

        $whitelist[ CampaignTrigger::FIRST_ORDER ] = [
            'points',
            'min_subtotal',
        ];

        $whitelist[ CampaignTrigger::ORDER_COUNT ] = [
            'points',
            'order_count',
            'repeat',
        ];

        $whitelist[ CampaignTrigger::SPEND_MILESTONE ] = [
            'points',
            'spend_amount',
            'repeat',
        ];

        return $whitelist;
    }

    /**
     * Widens the reward types this build offers; all_known() is unfiltered so a downgraded site still recognises them.
     *
     * @param string[] $types
     * @return string[]
     */
    public static function add_reward_types( array $types ): array {
        return array_values( array_unique( array_merge( $types, self::ADDED_REWARD_TYPES ) ) );
    }

    /** The handlers that spend `fixed_coupon` and `percent_coupon` rewards as virtual coupons on the cart. */
    public static function register_reward_handlers( Registry $registry ): void {
        $registry->register( new DiscountRewardHandler( RewardType::FIXED_COUPON ) );
        $registry->register( new DiscountRewardHandler( RewardType::PERCENT_COUPON ) );
    }

    /**
     * Typed config specs for the reward types Pro adds; their keys are the
     * only ones a config may hold.
     *
     * @param array<string, array<string, array<string, mixed>>> $schema
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function add_reward_config_schema( array $schema ): array {
        $currency     = [
            'type'     => 'currency',
            'required' => true,
        ];
        $money        = [
            'type' => 'nullable_number',
            'min'  => 0,
        ];
        $expiry_days  = [
            'type' => 'nullable_int',
            'min'  => 1,
        ];
        $exclude_sale = [ 'type' => 'bool' ];

        // A cap, not a threshold: null is no cap, and a set cap is above 0.
        $money_cap = [
            'type'          => 'nullable_number',
            'exclusive_min' => 0,
        ];

        $schema[ RewardType::FIXED_COUPON ] = [
            'currency'           => $currency,
            'amount'             => [
                'type'     => 'number',
                'min'      => 0.01,
                'required' => true,
            ],
            'min_order'          => $money,
            'coupon_expiry_days' => $expiry_days,
            'exclude_sale_items' => $exclude_sale,
        ];

        $schema[ RewardType::PERCENT_COUPON ] = [
            'percent'            => [
                'type'     => 'number',
                'min'      => 1,
                'max'      => 100,
                'required' => true,
            ],
            'max_discount'       => $money_cap,
            'currency'           => $currency,
            'min_order'          => $money,
            'coupon_expiry_days' => $expiry_days,
            'exclude_sale_items' => $exclude_sale,
        ];

        $schema[ RewardType::FREE_SHIPPING ] = [
            'min_order'          => $money,
            'currency'           => $currency,
            'coupon_expiry_days' => $expiry_days,
        ];

        $schema[ RewardType::FREE_PRODUCT ] = [
            'product_id'         => [
                'type'     => 'product',
                'required' => true,
            ],
            'variation_id'       => [
                'type' => 'nullable_int',
                'min'  => 1,
            ],
            'qty'                => [
                'type'     => 'int',
                'min'      => 1,
                'required' => true,
            ],
            'coupon_expiry_days' => $expiry_days,
        ];

        return $schema;
    }

    /**
     * Supplies the conditions evaluator Lite asks for.
     *
     * @param object|null $evaluator
     * @return object
     */
    public static function conditions_evaluator( $evaluator ) {
        // An evaluator a lower-priority subscriber supplied is left alone.
        return null === $evaluator ? self::evaluator() : $evaluator;
    }

    /** The one evaluator instance for this request. */
    private static function evaluator(): ConditionsEvaluator {
        if ( null === self::$evaluator ) {
            self::$evaluator = new ConditionsEvaluator();
        }

        return self::$evaluator;
    }

    /** Registers Pro's REST controllers. */
    public static function add_controllers( array $controllers ): array {
        $controllers[] = new CampaignPreviewRestController(
            new CampaignRepository(),
            self::evaluator()
        );

        $controllers[] = new CustomerSegmentsRestController();
        $controllers[] = new DashboardProRestController(
            new CampaignPointsRepository(),
            new CampaignRepository(),
            new DashboardCache( new DashboardRepository() )
        );
        $controllers[] = new CampaignPerformanceRestController(
            new DashboardRepository(),
            new CampaignRepository(),
            new CampaignPerformanceRepository(),
            new CampaignPointsRepository(),
            new LedgerRepository()
        );
        $controllers[] = new StoreLedgerRestController(
            new StoreLedgerRepository( new LedgerRepository() ),
            new LedgerRepository()
        );

        return $controllers;
    }

    /**
     * Adds the saved Customers-page segments to the admin bootstrap payload.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function add_segments( array $data ): array {
        $data['segments'] = CustomerSegmentsHelper::get_segments();

        return $data;
    }

    /**
     * Documents max_points_per_order in the campaign REST schema
     * so the UI can introspect them. Matched on the schema title, not
     * $rest_base: the preview controller shares the `campaigns` base.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public static function describe_pro_config( array $schema, string $rest_base ): array {
        if ( 'campaigns' !== $rest_base || 'yayloyalty_campaign' !== ( $schema['title'] ?? '' ) ) {
            return $schema;
        }

        if ( ! isset( $schema['properties']['config'] ) ) {
            return $schema;
        }

        $schema['properties']['config']['properties'] = [
            'max_points_per_order' => [
                'description' => __( 'Cap on points awarded by a single order. null means no cap.', 'yayloyalty' ),
                'type'        => [ 'integer', 'null' ],
                'minimum'     => 1,
            ],
        ];

        // Closing the object would reject every trigger-dependent key.
        $schema['properties']['config']['additionalProperties'] = true;

        return $schema;
    }
}
