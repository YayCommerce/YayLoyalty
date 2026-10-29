<?php
namespace YayLoyalty\Models;

use YayLoyalty\Enums\RewardType;
use YayLoyalty\Enums\Status;
use YayLoyalty\Utils\ConfigSpec;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Json;

defined( 'ABSPATH' ) || exit;

/**
 * A way to spend points. Like Campaign, it is mutable, has no service, and its invariants are validation.
 * `conditions` are facts about the customer being evaluated, such as user_role. `config` holds
 * properties of the reward itself, such as min_order. Only Installer writes rows so far.
 */
class Reward extends AbstractModel {

    /** config/conditions blob version. Bump when the shape changes. */
    public const CONFIG_SCHEMA_VERSION = 1;

    /** What a refund returns of the points a flexible discount spent: `config.refund_policy`. Engine\Redemption\Reversal applies it. */
    public const REFUND_POLICY_PROPORTIONAL = 'proportional';
    public const REFUND_POLICY_FULL         = 'full';
    public const REFUND_POLICY_NONE         = 'none';

    /** How the shopper spends points on a flexible discount: `config.redemption_mode`. A missing key reads as free input. */
    public const REDEMPTION_MODE_AUTO_MAX   = 'auto_max';
    public const REDEMPTION_MODE_FREE_INPUT = 'free_input';

    /** A `product` value is first an id: a whole number of at least 1. */
    private const POSITIVE_INT_SPEC = [
        'type' => 'int',
        'min'  => 1,
    ];

    /** @var int|null */
    private $id;

    /** @var string */
    private $name;

    /** @var string */
    private $slug;

    /** @var string */
    private $type;

    /** @var string */
    private $status;

    /** @var int */
    private $sort_order;

    /** @var int|null */
    private $cost_points;

    /** @var array */
    private $config;

    /** @var array */
    private $conditions;

    /** @var string|null */
    private $starts_at;

    /** @var string|null */
    private $ends_at;

    /** @var bool */
    private $is_builtin;

    /** @var int|null */
    private $created_by;

    /** @var int|null */
    private $updated_by;

    /** @var string */
    private $created_at;

    /** @var string */
    private $updated_at;

    /** @var string|null */
    private $archived_at;

    /** @var string `yayloyalty-` plus 8 characters, set once by RewardRepository::insert(); '' until then. */
    private $coupon_code;

    public function __construct(
        ?int $id,
        string $name,
        string $slug,
        string $type,
        string $status = Status::ACTIVE,
        int $sort_order = 0,
        ?int $cost_points = null,
        array $config = [],
        array $conditions = [],
        ?string $starts_at = null,
        ?string $ends_at = null,
        bool $is_builtin = false,
        ?int $created_by = null,
        ?int $updated_by = null,
        string $created_at = '',
        string $updated_at = '',
        ?string $archived_at = null,
        ?string $coupon_code = null
    ) {
        $this->id          = $id;
        $this->name        = $name;
        $this->slug        = $slug;
        $this->type        = $type;
        $this->status      = $status;
        $this->sort_order  = $sort_order;
        $this->cost_points = $cost_points;
        $this->config      = $config;
        $this->conditions  = $conditions;
        $this->starts_at   = $starts_at;
        $this->ends_at     = $ends_at;
        $this->is_builtin  = $is_builtin;
        $this->created_by  = $created_by;
        $this->updated_by  = $updated_by;
        $this->created_at  = $created_at;
        $this->updated_at  = $updated_at;
        $this->archived_at = $archived_at;
        $this->coupon_code = (string) $coupon_code;
    }

    public static function from_row( array $row ): self {
        return new self(
            self::cast_int( $row['id'] ?? null ),
            (string) ( $row['name'] ?? '' ),
            (string) ( $row['slug'] ?? '' ),
            (string) ( $row['type'] ?? '' ),
            (string) ( $row['status'] ?? Status::ACTIVE ),
            self::cast_int_default( $row['sort_order'] ?? 0 ),
            self::cast_int( $row['cost_points'] ?? null ),
            Json::decode( $row['config'] ?? null, [] ),
            Json::decode_list( $row['conditions'] ?? null, [] ),
            self::cast_datetime( $row['starts_at'] ?? null ),
            self::cast_datetime( $row['ends_at'] ?? null ),
            self::cast_bool( $row['is_builtin'] ?? 0 ),
            self::cast_int( $row['created_by'] ?? null ),
            self::cast_int( $row['updated_by'] ?? null ),
            (string) ( $row['created_at'] ?? '' ),
            (string) ( $row['updated_at'] ?? '' ),
            self::cast_datetime( $row['archived_at'] ?? null ),
            null === ( $row['coupon_code'] ?? null ) ? null : (string) $row['coupon_code']
        );
    }

    public function to_row(): array {
        return [
            'name'        => $this->name,
            'slug'        => $this->slug,
            'type'        => $this->type,
            'status'      => $this->status,
            'sort_order'  => $this->sort_order,
            'cost_points' => $this->cost_points,
            'config'      => Json::encode( $this->config, self::CONFIG_SCHEMA_VERSION ),
            'conditions'  => Json::encode_list( $this->conditions, self::CONFIG_SCHEMA_VERSION ),
            'starts_at'   => $this->starts_at,
            'ends_at'     => $this->ends_at,
            'is_builtin'  => $this->is_builtin ? 1 : 0,
            'created_by'  => $this->created_by,
            'updated_by'  => $this->updated_by,
            'created_at'  => $this->created_at,
            'updated_at'  => $this->updated_at,
            'archived_at' => $this->archived_at,
            'coupon_code' => '' === $this->coupon_code ? null : $this->coupon_code,
        ];
    }

    public function get_format(): array {
        return [ '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s' ];
    }

    /** Columns no update may change. */
    protected static function immutable_columns(): array {
        return [ 'slug', 'coupon_code', 'type', 'created_at', 'created_by', 'is_builtin' ];
    }

    public function to_array(): array {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'slug'        => $this->slug,
            'coupon_code' => $this->coupon_code,
            'type'        => $this->type,
            'status'      => $this->status,
            'sort_order'  => $this->sort_order,
            'cost_points' => $this->cost_points,
            'config'      => $this->config,
            'conditions'  => $this->conditions,
            'starts_at'   => $this->starts_at,
            'ends_at'     => $this->ends_at,
            'is_builtin'  => $this->is_builtin,
            'created_by'  => $this->created_by,
            'created_at'  => $this->created_at,
            'updated_at'  => $this->updated_at,
        ];
    }

    /** Whether this reward is live at $at, the event's timestamp. */
    public function is_live_at( string $at ): bool {
        return Status::is_live_at( $this->status, $this->starts_at, $this->ends_at, $at );
    }

    /** The label to show: the stored status, or `scheduled` / `ended` derived from the dates when `active`. */
    public function display_status( ?string $at = null ): string {
        return Status::resolve_display(
            $this->status,
            $this->starts_at,
            $this->ends_at,
            null !== $at && '' !== $at ? $at : Dates::now()
        );
    }

    /**
     * Per-type config schema, key => spec: what `config` may hold and what config_is_valid() checks.
     * Lite declares flexible_discount. Pro types add theirs through the filter.
     * Spec types: bool, string, int|number|nullable_int|nullable_number [min, max], enum|enum_list [values],
     * currency, product, and limit (null, or a whole number from 1). `required => true` makes a key mandatory. `exclusive_min` is a strict lower
     * bound: a nullable cap uses it at 0, so null is no cap and a set cap is above 0.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function config_schema(): array {
        $schema = [
            RewardType::FLEXIBLE_DISCOUNT => [
                'currency'                    => [
                    'type'     => 'currency',
                    'required' => true,
                ],
                'points_per_currency_unit'    => [
                    'type'          => 'number',
                    'exclusive_min' => 0,
                ],
                'min_points'                  => [
                    'type' => 'int',
                    'min'  => 0,
                ],
                // Points, so whole: null is no cap, as the purchase campaign's own cap.
                'max_points_per_order'        => [ 'type' => 'limit' ],
                'max_percent_of_discountable' => [
                    'type' => 'nullable_number',
                    'min'  => 0,
                ],
                'increment'                   => [
                    'type' => 'int',
                    'min'  => 1,
                ],
                'show_on'                     => [
                    'type'   => 'enum_list',
                    'values' => [ 'cart', 'checkout' ],
                ],
                'refund_policy'               => [
                    'type'   => 'enum',
                    'values' => [ self::REFUND_POLICY_PROPORTIONAL, self::REFUND_POLICY_FULL, self::REFUND_POLICY_NONE ],
                ],
                'redemption_mode'             => [
                    'type'   => 'enum',
                    'values' => [ self::REDEMPTION_MODE_AUTO_MAX, self::REDEMPTION_MODE_FREE_INPUT ],
                ],
                // Global redemption cap, null for unlimited. Stored, but nothing enforces it yet.
                'max_total'                   => [
                    'type'          => 'nullable_number',
                    'exclusive_min' => 0,
                ],
            ],
        ];

        /**
         * Typed config specs per reward type. Internal Pro seam, not a public API.
         *
         * @param array<string, array<string, array<string, mixed>>> $schema
         */
        return apply_filters( 'yayloyalty_reward_config_schema', $schema );
    }

    /**
     * Whether a config is acceptable for a type: unknown keys, wrong types and
     * a missing `required` key fail. A type this build has no schema for
     * came from another build and is accepted unjudged. The stored
     * schema_version stamp is skipped: a config read back from the API carries it.
     */
    public static function config_is_valid( string $type, array $config ): bool {
        $schema = self::config_schema()[ $type ] ?? null;

        if ( null === $schema ) {
            return true;
        }

        foreach ( $config as $key => $value ) {
            if ( Json::SCHEMA_VERSION_KEY === $key ) {
                continue;
            }

            if ( ! isset( $schema[ $key ] ) || ! self::value_matches_spec( $value, $schema[ $key ] ) ) {
                return false;
            }
        }

        foreach ( $schema as $key => $spec ) {
            if ( ! empty( $spec['required'] ) && ! array_key_exists( $key, $config ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Why a config that passes config_is_valid() still can't be saved, or null. Checks rules across keys:
     * a flexible discount's minimum must be at least its redemption increment, read as Clamp reads them.
     */
    public static function config_conflict( string $type, array $config ): ?string {
        if ( RewardType::FLEXIBLE_DISCOUNT !== $type ) {
            return null;
        }

        $min       = max( 0, (int) ( $config['min_points'] ?? 0 ) );
        $increment = max( 1, (int) ( $config['increment'] ?? 1 ) );

        return $min < $increment ? __( 'Minimum points must be at least the redemption increment.', 'yayloyalty' ) : null;
    }

    /**
     * The spec check, plus the reward-only `product` type.
     *
     * @param array<string, mixed> $spec
     */
    private static function value_matches_spec( $value, array $spec ): bool {
        if ( 'product' !== ( $spec['type'] ?? '' ) ) {
            return ConfigSpec::matches( $value, $spec );
        }

        if ( ! ConfigSpec::matches( $value, self::POSITIVE_INT_SPEC ) ) {
            return false;
        }

        // Skipped under plain PHPUnit, where WooCommerce is not loaded.
        return ! function_exists( 'wc_get_product' ) || self::is_rewardable_product( wc_get_product( (int) $value ) );
    }

    /**
     * Whether a free-product reward may point at $product: a parent product a
     * customer can be given, so not a variation and not trashed or unfinished
     * (draft, pending, scheduled). Private stays allowed: the store owns it.
     *
     * @param \WC_Product|false|null $product
     */
    private static function is_rewardable_product( $product ): bool {
        if ( ! $product || $product->is_type( 'variation' ) ) {
            return false;
        }

        return in_array( $product->get_status(), [ 'publish', 'private' ], true );
    }

    /**
     * Drops config keys that do not belong to the type. A type with no schema is preserved wholesale:
     * "unknown" must never mean "delete" on a downgrade.
     */
    public static function validate_config( string $type, array $config ): array {
        $schema = self::config_schema()[ $type ] ?? null;

        if ( null === $schema ) {
            return $config;
        }

        $clean = [];

        foreach ( array_keys( $schema ) as $key ) {
            if ( array_key_exists( $key, $config ) ) {
                $clean[ $key ] = $config[ $key ];
            }
        }

        return $clean;
    }

    /** Default config for a built-in reward. `currency` is the store's: it is required and has no type-only default. */
    public static function default_config( string $type ): array {
        switch ( $type ) {
            case RewardType::FLEXIBLE_DISCOUNT:
                return [
                    'currency'                    => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
                    'points_per_currency_unit'    => 100,
                    'min_points'                  => 100,
                    'max_points_per_order'        => null,
                    'max_percent_of_discountable' => null,
                    'increment'                   => 100,
                    'show_on'                     => [ 'cart', 'checkout' ],
                    'refund_policy'               => self::REFUND_POLICY_PROPORTIONAL,
                    'redemption_mode'             => self::REDEMPTION_MODE_AUTO_MAX,
                    'max_total'                   => null,
                ];

            default:
                return [];
        }
    }

    /** Whether a conditions list is acceptable to store. No controller calls it yet. */
    public static function conditions_are_valid( array $conditions ): bool {
        return RewardConditionSet::is_valid( $conditions );
    }

    /**
     * Whether cost_points fits the type. flexible_discount must be null because the customer picks the amount.
     * Every other type needs at least 1, since a null cost on a coupon type would be a free redemption.
     * Unknown types are accepted as they are.
     */
    public static function cost_points_is_valid( string $type, ?int $cost_points ): bool {
        if ( ! RewardType::is_known( $type ) ) {
            return true;
        }

        if ( RewardType::FLEXIBLE_DISCOUNT === $type ) {
            return null === $cost_points;
        }

        return null !== $cost_points && $cost_points >= 1;
    }

    public function get_id(): ?int {
        return $this->id;
    }

    public function get_name(): string {
        return $this->name;
    }

    public function get_slug(): string {
        return $this->slug;
    }

    /** The reward's virtual coupon code; '' only before its row is inserted. */
    public function get_coupon_code(): string {
        return $this->coupon_code;
    }

    public function get_type(): string {
        return $this->type;
    }

    public function get_status(): string {
        return $this->status;
    }

    public function get_sort_order(): int {
        return $this->sort_order;
    }

    public function get_cost_points(): ?int {
        return $this->cost_points;
    }

    public function get_config(): array {
        return $this->config;
    }

    public function get_conditions(): array {
        return $this->conditions;
    }

    public function get_starts_at(): ?string {
        return $this->starts_at;
    }

    public function get_ends_at(): ?string {
        return $this->ends_at;
    }

    public function get_created_by(): ?int {
        return $this->created_by;
    }

    public function get_updated_by(): ?int {
        return $this->updated_by;
    }

    public function get_archived_at(): ?string {
        return $this->archived_at;
    }

    public function is_builtin(): bool {
        return $this->is_builtin;
    }

    /** Whether this reward may be archived: never the built-in, which the Lite UI and DashboardRepository find by slug. */
    public function can_archive(): bool {
        return ! $this->is_builtin;
    }

    public function is_archived(): bool {
        return Status::ARCHIVED === $this->status;
    }

    /** Only RewardRepository::insert() calls this: the column is immutable once written. */
    public function set_coupon_code( string $coupon_code ): void {
        $this->coupon_code = $coupon_code;
    }

    public function set_name( string $name ): void {
        $this->name = $name;
    }

    /**
     * Sets the stored status and keeps archived_at in step. A built-in cannot
     * be archived: RewardRepository finds it by slug.
     *
     * @throws \InvalidArgumentException When $status is unknown, or when archiving a built-in.
     */
    public function set_status( string $status ): void {
        $status = Status::assert( $status );

        if ( Status::ARCHIVED === $status && ! $this->can_archive() ) {
            throw new \InvalidArgumentException( 'YayLoyalty: built-in rewards cannot be archived.' );
        }

        if ( Status::ARCHIVED === $status ) {
            if ( Status::ARCHIVED !== $this->status ) {
                $this->archived_at = Dates::now();
            }
        } else {
            $this->archived_at = null;
        }

        $this->status = $status;
    }

    public function set_sort_order( int $sort_order ): void {
        $this->sort_order = $sort_order;
    }

    public function set_cost_points( ?int $cost_points ): void {
        $this->cost_points = $cost_points;
    }

    public function set_config( array $config ): void {
        $this->config = self::validate_config( $this->type, $config );
    }

    /** Normalises a conditions list and stores it. Rejection is conditions_are_valid()'s job. */
    public function set_conditions( array $conditions ): void {
        $this->conditions = RewardConditionSet::normalize( $conditions );
    }

    public function set_starts_at( ?string $starts_at ): void {
        $this->starts_at = $starts_at;
    }

    public function set_ends_at( ?string $ends_at ): void {
        $this->ends_at = $ends_at;
    }

    public function set_updated_by( ?int $updated_by ): void {
        $this->updated_by = $updated_by;
    }

    public function set_updated_at( string $updated_at ): void {
        $this->updated_at = $updated_at;
    }
}
