<?php
namespace YayLoyalty\Models;

use YayLoyalty\Enums\RewardType;
use YayLoyalty\Enums\Status;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Json;

defined( 'ABSPATH' ) || exit;

/**
 * A way to spend points.
 *
 * Definition table, so this is mutable — the same shape as Campaign, and for
 * the same reason: no service, because its invariants (valid type, whitelisted
 * and correctly-typed config keys, the cost_points/type rule, derived
 * liveness, "built-ins are not archivable") are validation, not a cross-table
 * concern. A service wrapping one repository would be ceremony. These
 * validators exist but no write path calls them yet — only
 * Installer::seed_builtin_reward() writes rows.
 *
 * CONDITIONS VS CONFIG — the boundary is "is this a fact about the customer
 * being evaluated, or a property of the reward itself?" `conditions`
 * (validated by conditions_are_valid(), normalised by set_conditions(), see
 * Models\RewardConditionSet) holds customer-specific eligibility rules such as
 * `redemption_count` — how many times THIS customer has already redeemed THIS
 * reward. `config`'s `max_total` looks similar but is not a condition: it is a
 * single global cap on the reward across every customer, so it stays a
 * reward-level property in `config`.
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
            // A list, not a map — see Json::encode_list().
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

    /**
     * Is this reward live at a given instant?
     *
     * Delegates to Status::is_live_at() so campaigns, rewards (and tiers, when
     * they land) share one implementation of the rule rather than three copies.
     */
    public function is_live_at( string $at ): bool {
        return Status::is_live_at( $this->status, $this->starts_at, $this->ends_at, $at );
    }

    /**
     * The label to show for this reward: its stored status, or — when that is
     * `active` — `scheduled` / `ended` derived from the date window.
     */
    public function display_status( ?string $at = null ): string {
        return Status::resolve_display(
            $this->status,
            $this->starts_at,
            $this->ends_at,
            null !== $at && '' !== $at ? $at : Dates::now()
        );
    }

    /**
     * Per-type config schema: key => spec.
     *
     * The source of truth for what a reward's `config` may hold, and what
     * config_is_valid() checks each value against — this validator exists but
     * no write path calls it yet.
     *
     * Covers `flexible_discount`, the type Lite itself offers. The four Pro
     * types — fixed_coupon, percent_coupon, free_shipping, free_product —
     * carry their own key vocabulary, contributed through the
     * yayloyalty_reward_config_whitelist seam (see config_whitelist()); Lite
     * neither describes nor type-checks them, mirroring how Campaign treats
     * its own Pro-only triggers.
     *
     * Spec shapes:
     *   'bool'                          — a JSON boolean
     *   'string'                        — a JSON string
     *   'int'    [ 'min' => n ]         — a whole number, optional lower bound
     *   'number' [ 'min' => n ]         — int or float, optional lower bound
     *   'enum'   [ 'values' => [...] ]  — a string from a fixed set
     *   'enum_list' [ 'values' => [...] ] — a list of strings from a fixed set
     *   'currency'                      — an ISO 4217 code
     *   'nullable_number' [ 'min' => n ] — null (unlimited), or a number with an
     *                                      optional lower bound
     *
     *   'required' => true              — on any spec, the key must be present,
     *                                      not merely correctly typed if given.
     *                                      Only `currency` uses this: without
     *                                      it, an amount or a points-to-currency
     *                                      rate is ambiguous on any store whose
     *                                      base currency changes or that sells
     *                                      in more than one.
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
                // Global redemption cap; null = unlimited. Nothing enforces it
                // yet, but it is stored from 1.0 so an uncapped discount cannot
                // be drained further than the merchant intended once something
                // does.
                'max_total'                => [
                    'type' => 'nullable_number',
                    'min'  => 0,
                ],
            ],
        ];
    }

    /**
     * Keys each type's config may contain, keyed by type.
     *
     * Derived from config_schema() so the two never drift, then passed through
     * the Pro seam — the filter is for Pro to add whitelists for types Lite has
     * no schema entry for at all, not to add keys to flexible_discount's.
     *
     * @return array<string, string[]>
     */
    public static function config_whitelist(): array {
        $whitelist = [];

        foreach ( self::config_schema() as $type => $keys ) {
            $whitelist[ $type ] = array_keys( $keys );
        }

        /**
         * Config keys each reward type may store, keyed by type.
         *
         * Pro's attach point for its own types' config. Internal seam for
         * first-party Pro code, not a documented third-party API.
         *
         * @param array<string, string[]> $whitelist
         */
        return apply_filters( 'yayloyalty_reward_config_whitelist', $whitelist );
    }

    /**
     * Is this config acceptable for this type?
     *
     * Rejects unknown keys, wrong value types, and a missing `required` key —
     * mirrors Campaign::config_is_valid(), extended with the required-key
     * check that rule needs (Campaign's schema has no required keys today).
     * No write path calls this yet — only Installer::seed_builtin_reward()
     * writes rows. Same downgrade safety: a type this build has no schema and
     * no whitelist for was created by another build, so it is accepted
     * wholesale rather than rejected.
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

            // A key the filtered whitelist allows but config_schema() does not
            // describe is a Pro type's own key: accept it untyped.
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

    /**
     * ISO 4217. Format-checked always; membership-checked against
     * WooCommerce's currency list when it is loaded (this plugin requires
     * WooCommerce, so that is the normal path — the fallback keeps this class
     * usable in isolation, e.g. under a plain PHPUnit run).
     */
    private static function is_currency_code( string $code ): bool {
        if ( 1 !== preg_match( '/^[A-Z]{3}$/', $code ) ) {
            return false;
        }

        if ( function_exists( 'get_woocommerce_currencies' ) ) {
            return array_key_exists( $code, get_woocommerce_currencies() );
        }

        return true;
    }

    /**
     * Drop config keys that do not belong to this type.
     *
     * Mirrors Campaign::validate_config(): unknown keys are discarded rather
     * than stored; a type this build has no whitelist for is preserved
     * wholesale, because "unknown" must never mean "delete" a merchant's
     * configuration on a downgrade.
     *
     * @param array $config
     * @return array
     */
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

    /**
     * Default config for a built-in reward.
     *
     * `currency` falls back to the store's currency, since this key is
     * required wherever config carries a money value and there is no sane
     * type-only default for it.
     *
     * @return array
     */
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

    /**
     * Are these conditions acceptable to store?
     *
     * Mirrors config_is_valid(): a non-throwing check, not yet wired into a
     * controller (no reward controller exists). Rejects an unknown type, an
     * operator a type does not accept, a wrong value shape, an empty list
     * value, a duplicate type, and customer_ids past its cap. See
     * Models\RewardConditionSet.
     */
    public static function conditions_are_valid( array $conditions ): bool {
        return RewardConditionSet::is_valid( $conditions );
    }

    /**
     * Is this cost_points acceptable for this type?
     *
     * The one rule the database cannot express: `flexible_discount` must have
     * a null cost_points — the customer picks the amount on a slider — and
     * every other type must have one set and >= 1. Enforces both directions,
     * but no write path calls it yet — only Installer::seed_builtin_reward()
     * writes rows, so a fixed_coupon with a null cost today would only be a
     * free redemption once a write path skips this check.
     *
     * A type this build does not recognise at all was created by another
     * build and is accepted without judgement, same downgrade-safety
     * reasoning as config_is_valid().
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
     * Set the stored status.
     *
     * A built-in reward cannot be archived, for the same reason a built-in
     * campaign cannot: DashboardRepository depends on finding it by slug
     * ('cart-discount', via RewardRepository::find_by_slug()) and expects it
     * to exist. archived_at is kept in step with status here — stamped on the
     * way in, cleared on the way out — so no caller has to remember to.
     *
     * @throws \InvalidArgumentException When $status is unknown, or when
     *                                   archiving a built-in.
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

    /**
     * Normalise a conditions list and store it.
     *
     * Mirrors set_config(): the value that lands on the model is the
     * canonical one — scalars widened to arrays, ids cast to int, role slugs
     * lower-cased, lists de-duplicated, entries ordered cheap-first for
     * evaluation. Rejection of a malformed set is conditions_are_valid()'s
     * job, but no write path calls it yet. See Models\RewardConditionSet.
     */
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
