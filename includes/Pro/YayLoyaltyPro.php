<?php
namespace YayLoyalty\Pro;

use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\RewardType;
use YayLoyalty\Pro\Controllers\CampaignPreviewRestController;
use YayLoyalty\Pro\Controllers\CustomerSegmentsRestController;
use YayLoyalty\Pro\Helpers\CustomerSegmentsHelper;
use YayLoyalty\Pro\Services\ConditionsEvaluator;
use YayLoyalty\Repositories\CampaignRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Pro entry point.
 *
 * THIS ENTIRE DIRECTORY IS DELETED FOR THE LITE BUILD. Nothing outside
 * includes/Pro/ may reference a class inside it, with exactly one
 * exception: the guarded class_exists probe in YayLoyalty::initialize().
 *
 * That single guarded reference is what makes the split a directory deletion
 * rather than surgery on shared files — no @pro-start markers anywhere.
 *
 * WIRING ONLY. boot() attaches hooks and constructs collaborators; it does not
 * implement behaviour. Every feature lives in its own layer directory
 * (Models/, Repositories/, Services/, Controllers/, Database/Schema/) mirroring
 * the structure of the Lite tree, so a reader always knows where to look.
 *
 * Pro reaches Lite only through the seams documented in
 * docs/code-standards.md section 13:
 *
 *   yayloyalty_table_schemas
 *   yayloyalty_ledger_recorded
 *   yayloyalty_rest_controllers
 *   yayloyalty_campaign_triggers
 *   yayloyalty_campaign_config_whitelist
 *   yayloyalty_reward_types
 *   yayloyalty_reward_config_whitelist
 *   yayloyalty_rest_item_schema
 *   yayloyalty_campaign_conditions_evaluator
 *   yayloyalty_localize_admin_data
 *
 * If a feature needs something no seam provides, add a seam to Lite. Reaching
 * for a marker instead is the thing this design exists to avoid.
 */
final class YayLoyaltyPro {

    /** Guards against double-booting if initialize() ever runs twice. */
    private static $booted = false;

    /**
     * Shared across the triggers, whitelist and evaluator filters.
     *
     * One instance so its per-request memo of a customer's purchased products
     * is actually reused: an award pass evaluating several campaigns for the
     * same customer would otherwise rescan their orders for each one.
     *
     * @var ConditionsEvaluator|null
     */
    private static $evaluator = null;

    /**
     * Campaign triggers Pro adds to the three Lite ships.
     *
     * A subset of CampaignTrigger's Pro constants: `referral`, `social` and
     * `renewal` are recognised when stored but not yet fireable by anything,
     * so offering them would sell a switch that does nothing.
     *
     * @var string[]
     */
    private const ADDED_TRIGGERS = [
        CampaignTrigger::BIRTHDAY,
        CampaignTrigger::FIRST_ORDER,
        CampaignTrigger::ORDER_COUNT,
        CampaignTrigger::SPEND_MILESTONE,
    ];

    /**
     * Reward types Pro adds to the one Lite ships.
     *
     * @var string[]
     */
    private const ADDED_REWARD_TYPES = [
        RewardType::FIXED_COUPON,
        RewardType::PERCENT_COUPON,
        RewardType::FREE_SHIPPING,
        RewardType::FREE_PRODUCT,
    ];

    /**
     * Attach Pro to Lite's extension seams.
     *
     * Called from YayLoyalty::initialize() behind a class_exists check, so in
     * the Lite build this method simply does not exist and is never reached.
     *
     * Runs on plugins_loaded, before rest_api_init, so the trigger and schema
     * filters are in place by the time routes are registered with their enums.
     */
    public static function boot(): void {
        if ( self::$booted ) {
            return;
        }

        self::$booted = true;

        add_filter( 'yayloyalty_campaign_triggers', [ self::class, 'add_triggers' ] );
        add_filter( 'yayloyalty_campaign_config_whitelist', [ self::class, 'add_config_keys' ] );
        add_filter( 'yayloyalty_reward_types', [ self::class, 'add_reward_types' ] );
        add_filter( 'yayloyalty_reward_config_whitelist', [ self::class, 'add_reward_config_keys' ] );
        add_filter( 'yayloyalty_campaign_conditions_evaluator', [ self::class, 'conditions_evaluator' ] );
        add_filter( 'yayloyalty_rest_controllers', [ self::class, 'add_controllers' ] );
        add_filter( 'yayloyalty_rest_item_schema', [ self::class, 'describe_pro_config' ], 10, 2 );
        add_filter( 'yayloyalty_localize_admin_data', [ self::class, 'add_segments' ] );

        /**
         * Fires once Pro has attached its hooks.
         *
         * The extension point for Pro-internal add-ons; not part of the
         * Lite build's surface.
         */
        do_action( 'yayloyalty_pro_booted' );
    }

    /**
     * Has Pro booted in this request?
     *
     * Distinct from YayLoyalty::is_pro(), which only asks whether the code
     * is present. Useful when ordering matters.
     */
    public static function is_booted(): bool {
        return self::$booted;
    }

    /* ─── Seam subscribers ──────────────────────────────────────────── */

    /**
     * Widen the triggers this build can fire.
     *
     * CampaignTrigger::all_known() is deliberately NOT filtered, so this does
     * not change what a downgraded site recognises — only what Pro offers.
     *
     * @param string[] $triggers
     * @return string[]
     */
    public static function add_triggers( array $triggers ): array {
        return array_values( array_unique( array_merge( $triggers, self::ADDED_TRIGGERS ) ) );
    }

    /**
     * Config keys for the triggers Pro adds.
     *
     * Note what is NOT here: `max_points_per_order` and `daily_cap` on
     * `purchase`. Lite already whitelists those so a downgraded site keeps
     * them on re-save — vocabulary is shared, behaviour is not. Adding them
     * again would be a no-op that implied otherwise.
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
     * Widen the reward types this build can offer.
     *
     * RewardType::all_known() is deliberately NOT filtered, so this does not
     * change what a downgraded site recognises — only what Pro offers.
     *
     * @param string[] $types
     * @return string[]
     */
    public static function add_reward_types( array $types ): array {
        return array_values( array_unique( array_merge( $types, self::ADDED_REWARD_TYPES ) ) );
    }

    /**
     * Config keys for the reward types Pro adds.
     *
     * @param array<string, string[]> $whitelist
     * @return array<string, string[]>
     */
    public static function add_reward_config_keys( array $whitelist ): array {
        $whitelist[ RewardType::FIXED_COUPON ] = [
            'currency',
            'amount',
            'min_order',
            'coupon_expiry_days',
            'exclude_sale_items',
            'max_total',
        ];

        $whitelist[ RewardType::PERCENT_COUPON ] = [
            'percent',
            'max_discount',
            'currency',
            'min_order',
            'coupon_expiry_days',
            'exclude_sale_items',
            'max_total',
        ];

        $whitelist[ RewardType::FREE_SHIPPING ] = [
            'min_order',
            'currency',
            'coupon_expiry_days',
            'max_total',
        ];

        $whitelist[ RewardType::FREE_PRODUCT ] = [
            'product_id',
            'variation_id',
            'qty',
            'coupon_expiry_days',
            'max_total',
        ];

        return $whitelist;
    }

    /**
     * Supply the conditions evaluator Lite asks for and never has.
     *
     * @param object|null $evaluator
     * @return object
     */
    public static function conditions_evaluator( $evaluator ) {
        // Pro is the default provider, not the only possible one. An evaluator
        // a lower-priority subscriber already supplied is left alone rather
        // than clobbered.
        return null === $evaluator ? self::evaluator() : $evaluator;
    }

    /**
     * The one evaluator instance for this request.
     */
    private static function evaluator(): ConditionsEvaluator {
        if ( null === self::$evaluator ) {
            self::$evaluator = new ConditionsEvaluator();
        }

        return self::$evaluator;
    }

    /**
     * Register Pro's REST controllers.
     *
     * @param array $controllers
     * @return array
     */
    public static function add_controllers( array $controllers ): array {
        $controllers[] = new CampaignPreviewRestController(
            new CampaignRepository(),
            self::evaluator()
        );

        $controllers[] = new CustomerSegmentsRestController();

        return $controllers;
    }

    /**
     * Add the saved Customers-page segments to the admin bootstrap payload.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function add_segments( array $data ): array {
        $data['segments'] = CustomerSegmentsHelper::get_segments();

        return $data;
    }

    /**
     * Describe the capping keys Lite stores but cannot act on.
     *
     * Keyed on the schema's title, not on $rest_base: CampaignPreviewRestController
     * shares the `campaigns` base, so matching on the base alone would inject
     * campaign config properties into the preview route's schema too.
     *
     * `config` stays an open object — its keys depend on the trigger, and the
     * per-trigger whitelist in Campaign is the authority. This only names the
     * two Pro keys so the REST schema documents them and the UI can introspect
     * them rather than hard-coding a list.
     *
     * @param array<string, mixed> $schema
     * @param string               $rest_base
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
                'description' => __( 'Cap on points awarded by a single order. 0 means no cap.', 'yayloyalty' ),
                'type'        => 'integer',
                'minimum'     => 0,
            ],
            'daily_cap'            => [
                'description' => __( 'Cap on points this campaign may award one customer per day. 0 means no cap.', 'yayloyalty' ),
                'type'        => 'integer',
                'minimum'     => 0,
            ],
        ];

        // Trigger-dependent keys are validated by Campaign::validate_config(),
        // not here. Closing the object would reject every other config key.
        $schema['properties']['config']['additionalProperties'] = true;

        return $schema;
    }
}
