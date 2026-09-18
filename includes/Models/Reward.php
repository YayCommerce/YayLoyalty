<?php
namespace YayLoyalty\Models;

use YayLoyalty\Enums\RewardType;
use YayLoyalty\Enums\Status;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Json;

defined( 'ABSPATH' ) || exit;

/**
 * A way to spend points. Same shape as Campaign: mutable, no service, its
 * invariants are validation. `conditions` are facts about the customer being
 * evaluated (e.g. redemption_count); `config` holds properties of the reward
 * itself (e.g. the global max_total cap). Only Installer writes rows so far.
 */
class Reward extends AbstractModel {

    /** config/conditions blob version. Bump when the shape changes. */
    public const CONFIG_SCHEMA_VERSION = 1;

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
        ?string $archived_at = null
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
            self::cast_datetime( $row['archived_at'] ?? null )
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
        ];
    }

    public function get_format(): array {
        return [ '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s' ];
    }

    public function to_array(): array {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'slug'        => $this->slug,
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

    /** The label to show: the stored status, or `scheduled` / `ended` derived from the date window when it is `active`. */
    public function display_status( ?string $at = null ): string {
        return Status::resolve_display(
            $this->status,
            $this->starts_at,
            $this->ends_at,
            null !== $at && '' !== $at ? $at : Dates::now()
        );
    }

    /**
     * Per-type config schema, key => spec: what `config` may hold and what
     * config_is_valid() checks against. Lite's flexible_discount only; Pro
     * types add theirs through config_whitelist(). Spec types: bool, string,
     * int|number [min], enum|enum_list [values], currency, nullable_number
     * [min]; `required => true` makes the key mandatory (only currency: a
     * money value without it is ambiguous once the store currency changes).
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function config_schema(): array {
        return [
            RewardType::FLEXIBLE_DISCOUNT => [
                'currency'                 => [
                    'type'     => 'currency',
                    'required' => true,
                ],
                'points_per_currency_unit' => [
                    'type' => 'number',
                    'min'  => 0,
                ],
                'min_points'               => [
                    'type' => 'int',
                    'min'  => 0,
                ],
                'max_points_per_order'     => [
                    'type' => 'nullable_number',
                    'min'  => 0,
                ],
                'max_percent_of_subtotal'  => [
                    'type' => 'nullable_number',
                    'min'  => 0,
                ],
                'increment'                => [
                    'type' => 'int',
                    'min'  => 1,
                ],
                'show_on'                  => [
                    'type'   => 'enum_list',
                    'values' => [ 'cart', 'checkout' ],
                ],
                'refund_policy'            => [
                    'type'   => 'enum',
                    'values' => [ 'proportional', 'full', 'none' ],
                ],
                // Global redemption cap, null = unlimited. Stored from 1.0 although unenforced yet.
                'max_total'                => [
                    'type' => 'nullable_number',
                    'min'  => 0,
                ],
            ],
        ];
    }

    /**
     * Keys each type's config may contain, keyed by type. Derived from
     * config_schema() so the two never drift; the filter is for Pro to add
     * whole types, not keys to flexible_discount's.
     *
     * @return array<string, string[]>
     */
    public static function config_whitelist(): array {
        $whitelist = [];

        foreach ( self::config_schema() as $type => $keys ) {
            $whitelist[ $type ] = array_keys( $keys );
        }

        /**
         * Config keys each reward type may store. Internal Pro seam, not a public API.
         *
         * @param array<string, string[]> $whitelist
         */
        return apply_filters( 'yayloyalty_reward_config_whitelist', $whitelist );
    }

    /**
     * Whether a config is acceptable for a type: unknown keys, wrong types and
     * a missing `required` key fail. A type this build has no schema or
     * whitelist for came from another build and is accepted unjudged.
     */
    public static function config_is_valid( string $type, array $config ): bool {
        $schema    = self::config_schema()[ $type ] ?? null;
        $whitelist = self::config_whitelist()[ $type ] ?? null;

        if ( null === $schema && null === $whitelist ) {
            return true;
        }

        $allowed = null !== $whitelist ? $whitelist : array_keys( (array) $schema );

        foreach ( $config as $key => $value ) {
            if ( ! in_array( $key, $allowed, true ) ) {
                return false;
            }

            // Whitelisted but not in config_schema(): a Pro type's own key, accepted untyped.
            $spec = is_array( $schema ) ? ( $schema[ $key ] ?? null ) : null;

            if ( null !== $spec && ! self::value_matches_spec( $value, $spec ) ) {
                return false;
            }
        }

        if ( is_array( $schema ) ) {
            foreach ( $schema as $key => $spec ) {
                if ( ! empty( $spec['required'] ) && ! array_key_exists( $key, $config ) ) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param mixed                $value
     * @param array<string, mixed> $spec
     */
    private static function value_matches_spec( $value, array $spec ): bool {
        switch ( $spec['type'] ?? '' ) {
            case 'bool':
                return is_bool( $value );

            case 'string':
                return is_string( $value );

            case 'enum':
                return is_string( $value ) && in_array( $value, (array) ( $spec['values'] ?? [] ), true );

            case 'enum_list':
                if ( ! is_array( $value ) ) {
                    return false;
                }

                foreach ( $value as $item ) {
                    if ( ! is_string( $item ) || ! in_array( $item, (array) ( $spec['values'] ?? [] ), true ) ) {
                        return false;
                    }
                }

                return true;

            case 'currency':
                return is_string( $value ) && self::is_currency_code( $value );

            case 'int':
                if ( is_bool( $value ) || ! is_numeric( $value ) || floor( (float) $value ) !== (float) $value ) {
                    return false;
                }

                return ! isset( $spec['min'] ) || (float) $value >= (float) $spec['min'];

            case 'number':
                if ( is_bool( $value ) || ! is_numeric( $value ) ) {
                    return false;
                }

                return ! isset( $spec['min'] ) || (float) $value >= (float) $spec['min'];

            case 'nullable_number':
                if ( null === $value ) {
                    return true;
                }

                if ( is_bool( $value ) || ! is_numeric( $value ) ) {
                    return false;
                }

                return ! isset( $spec['min'] ) || (float) $value >= (float) $spec['min'];

            default:
                return true;
        }
    }

    /** ISO 4217 format check, plus membership in WooCommerce's list when it is loaded (not under plain PHPUnit). */
    private static function is_currency_code( string $code ): bool {
        if ( 1 !== preg_match( '/^[A-Z]{3}$/', $code ) ) {
            return false;
        }

        if ( function_exists( 'get_woocommerce_currencies' ) ) {
            return array_key_exists( $code, get_woocommerce_currencies() );
        }

        return true;
    }

    /** Drops config keys that do not belong to the type. A type with no whitelist is preserved wholesale: "unknown" must never mean "delete" on a downgrade. */
    public static function validate_config( string $type, array $config ): array {
        $whitelist = self::config_whitelist()[ $type ] ?? null;

        if ( null === $whitelist ) {
            return $config;
        }

        $clean = [];

        foreach ( $whitelist as $key ) {
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
                    'currency'                 => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
                    'points_per_currency_unit' => 100,
                    'min_points'               => 100,
                    'max_points_per_order'     => null,
                    'max_percent_of_subtotal'  => null,
                    'increment'                => 100,
                    'show_on'                  => [ 'cart', 'checkout' ],
                    'refund_policy'            => 'proportional',
                    'max_total'                => null,
                ];

            default:
                return [];
        }
    }

    /** Whether a conditions list is acceptable to store. See RewardConditionSet. Not yet wired into a controller. */
    public static function conditions_are_valid( array $conditions ): bool {
        return RewardConditionSet::is_valid( $conditions );
    }

    /**
     * Whether cost_points fits the type: flexible_discount must be null (the
     * customer picks the amount), every other type >= 1. A null cost on a
     * coupon type would be a free redemption. Unknown types are accepted unjudged.
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

    public function is_archived(): bool {
        return Status::ARCHIVED === $this->status;
    }

    public function set_name( string $name ): void {
        $this->name = $name;
    }

    /**
     * Sets the stored status and keeps archived_at in step. A built-in cannot
     * be archived: DashboardRepository finds it by slug.
     *
     * @throws \InvalidArgumentException When $status is unknown, or when archiving a built-in.
     */
    public function set_status( string $status ): void {
        $status = Status::assert( $status );

        if ( Status::ARCHIVED === $status && $this->is_builtin ) {
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

    /** Normalises a conditions list (see RewardConditionSet) and stores it. Rejection is conditions_are_valid()'s job. */
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
