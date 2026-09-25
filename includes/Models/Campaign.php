<?php
namespace YayLoyalty\Models;

use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\Status;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Json;

defined( 'ABSPATH' ) || exit;

/**
 * An earning rule. Mutable (a definition, unlike LedgerEntry) and without a
 * service: its invariants are validation, which lives here and in the
 * controller schema. The DB column is `trigger_type`; everywhere else the
 * field is `trigger`, mapped only in from_row()/to_row().
 */
class Campaign extends AbstractModel {

    /** config/conditions blob version. Bump when the shape changes. */
    public const CONFIG_SCHEMA_VERSION = 1;

    /** @var int|null */
    private $id;

    /** @var string */
    private $name;

    /** @var string */
    private $slug;

    /** @var string */
    private $trigger;

    /** @var string */
    private $status;

    /** @var int */
    private $priority;

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
        string $trigger,
        string $status = Status::ACTIVE,
        int $priority = 0,
        array $config = [],
        array $conditions = [],
        ?string $starts_at = null,
        ?string $ends_at = null,
        bool $is_builtin = false,
        ?int $created_by = null,
        string $created_at = '',
        string $updated_at = '',
        ?int $updated_by = null,
        ?string $archived_at = null
    ) {
        $this->id          = $id;
        $this->name        = $name;
        $this->slug        = $slug;
        $this->trigger     = $trigger;
        $this->status      = $status;
        $this->priority    = $priority;
        $this->config      = $config;
        $this->conditions  = $conditions;
        $this->starts_at   = $starts_at;
        $this->ends_at     = $ends_at;
        $this->is_builtin  = $is_builtin;
        $this->created_by  = $created_by;
        $this->created_at  = $created_at;
        $this->updated_at  = $updated_at;
        $this->updated_by  = $updated_by;
        $this->archived_at = $archived_at;
    }

    public static function from_row( array $row ): self {
        return new self(
            self::cast_int( $row['id'] ?? null ),
            (string) ( $row['name'] ?? '' ),
            (string) ( $row['slug'] ?? '' ),
            (string) ( $row['trigger_type'] ?? '' ),
            (string) ( $row['status'] ?? Status::ACTIVE ),
            self::cast_int_default( $row['priority'] ?? 0 ),
            Json::decode( $row['config'] ?? null, [] ),
            Json::decode_list( $row['conditions'] ?? null, [] ),
            self::cast_datetime( $row['starts_at'] ?? null ),
            self::cast_datetime( $row['ends_at'] ?? null ),
            self::cast_bool( $row['is_builtin'] ?? 0 ),
            self::cast_int( $row['created_by'] ?? null ),
            (string) ( $row['created_at'] ?? '' ),
            (string) ( $row['updated_at'] ?? '' ),
            self::cast_int( $row['updated_by'] ?? null ),
            self::cast_datetime( $row['archived_at'] ?? null )
        );
    }

    public function to_row(): array {
        return [
            'name'         => $this->name,
            'slug'         => $this->slug,
            'trigger_type' => $this->trigger,
            'status'       => $this->status,
            'priority'     => $this->priority,
            'config'       => Json::encode( $this->config, self::CONFIG_SCHEMA_VERSION ),
            'conditions'   => Json::encode_list( $this->conditions, self::CONFIG_SCHEMA_VERSION ),
            'starts_at'    => $this->starts_at,
            'ends_at'      => $this->ends_at,
            'is_builtin'   => $this->is_builtin ? 1 : 0,
            'created_by'   => $this->created_by,
            'updated_by'   => $this->updated_by,
            'created_at'   => $this->created_at,
            'updated_at'   => $this->updated_at,
            'archived_at'  => $this->archived_at,
        ];
    }

    public function get_format(): array {
        return [ '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s' ];
    }

    /**
     * to_row() minus the columns an update must never touch (slug, trigger_type,
     * created_at, created_by, is_builtin), with get_format() filtered to match
     * position-for-position. $wpdb->update()'s $format is consumed by
     * array_shift() — purely positional — so dropping columns from the data
     * without dropping the same positions from the format silently shifts
     * every placeholder after the cut. Pairing them here, once, is cheaper
     * than auditing every caller.
     *
     * @return array{data: array<string, mixed>, format: string[]}
     */
    public function to_update_row(): array {
        $row    = $this->to_row();
        $format = $this->get_format();
        $keys   = array_keys( $row );

        $immutable = [ 'slug', 'trigger_type', 'created_at', 'created_by', 'is_builtin' ];

        $data        = [];
        $data_format = [];

        foreach ( $keys as $index => $key ) {
            if ( in_array( $key, $immutable, true ) ) {
                continue;
            }

            $data[ $key ]  = $row[ $key ];
            $data_format[] = $format[ $index ];
        }

        return [
            'data'   => $data,
            'format' => $data_format,
        ];
    }

    public function to_array(): array {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'slug'       => $this->slug,
            'trigger'    => $this->trigger,
            'status'     => $this->status,
            'priority'   => $this->priority,
            'config'     => $this->config,
            'conditions' => $this->conditions,
            'starts_at'  => $this->starts_at,
            'ends_at'    => $this->ends_at,
            'is_builtin' => $this->is_builtin,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * Whether this campaign is live at $at: the event's timestamp, never now(),
     * so a late-processed order is scored under the rules live when it happened.
     */
    public function is_live_at( string $at ): bool {
        return Status::is_live_at( $this->status, $this->starts_at, $this->ends_at, $at );
    }

    /**
     * The label to show: the stored status, or `scheduled` / `ended` derived
     * from the date window when it is `active`.
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
     * Per-trigger config schema, key => spec: what `config` may hold and what
     * config_is_valid() checks against. Lite triggers only; Pro triggers add
     * theirs through config_whitelist(). Spec types: bool, string,
     * int|number [min], enum [values], int_list.
     *
     * Pro-recognised keys are listed here on purpose: a downgraded site must
     * keep its stored values so an upgrade restores the campaign intact.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function config_schema(): array {
        return [
            CampaignTrigger::PURCHASE => [
                // Above 0, not from 0: a campaign that matches an order and earns 0 still stops the
                // lower-priority campaigns below it from applying (PointsEngine takes the first match).
                'points_per_unit'       => [
                    'type'          => 'number',
                    'exclusive_min' => 0,
                ],
                'mode'                  => [
                    'type'   => 'enum',
                    'values' => [ 'rate', 'multiplier' ],
                ],
                'multiplier'            => [
                    'type'          => 'number',
                    'exclusive_min' => 0,
                ],
                'base'                  => [
                    'type'   => 'enum',
                    'values' => [ 'after_discounts', 'before_discounts' ],
                ],
                'include_shipping'      => [ 'type' => 'bool' ],
                'include_tax'           => [ 'type' => 'bool' ],
                'rounding'              => [
                    'type'   => 'enum',
                    'values' => [ 'down', 'nearest', 'up' ],
                ],
                'min_subtotal'          => [
                    'type' => 'number',
                    'min'  => 0,
                ],
                'exclude_coupon_orders' => [ 'type' => 'bool' ],
                'excluded_product_ids'  => [ 'type' => 'int_list' ],
                'excluded_category_ids' => [ 'type' => 'int_list' ],
                'max_points_per_order'  => [
                    'type' => 'int',
                    'min'  => 0,
                ],
                'daily_cap'             => [
                    'type' => 'int',
                    'min'  => 0,
                ],
            ],
            CampaignTrigger::SIGNUP   => [
                'points' => [
                    'type' => 'int',
                    'min'  => 0,
                ],
            ],
            CampaignTrigger::REVIEW   => [
                'points'             => [
                    'type' => 'int',
                    'min'  => 0,
                ],
                'require_verified'   => [ 'type' => 'bool' ],
                'one_per_product'    => [ 'type' => 'bool' ],
                'daily_cap'          => [
                    'type' => 'int',
                    'min'  => 0,
                ],
                'media_bonus_points' => [
                    'type' => 'int',
                    'min'  => 0,
                ],
            ],
        ];
    }

    /**
     * Keys each trigger's config may contain, keyed by trigger. Derived from
     * config_schema() so the two never drift; the filter is for Pro to add
     * whole triggers, not keys to these.
     *
     * @return array<string, string[]>
     */
    public static function config_whitelist(): array {
        $whitelist = [];

        foreach ( self::config_schema() as $trigger => $keys ) {
            $whitelist[ $trigger ] = array_keys( $keys );
        }

        /**
         * Config keys each campaign trigger may store. Internal Pro seam, not a public API.
         *
         * @param array<string, string[]> $whitelist
         */
        return apply_filters( 'yayloyalty_campaign_config_whitelist', $whitelist );
    }

    /**
     * Whether a config is acceptable for a trigger; the controller answers 4xx
     * on false. A trigger this build has no schema or whitelist for came from
     * another build (Pro campaign on a downgraded site) and is accepted unjudged.
     */
    public static function config_is_valid( string $trigger, array $config ): bool {
        $schema    = self::config_schema()[ $trigger ] ?? null;
        $whitelist = self::config_whitelist()[ $trigger ] ?? null;

        if ( null === $schema && null === $whitelist ) {
            return true;
        }

        $allowed = null !== $whitelist ? $whitelist : array_keys( (array) $schema );

        foreach ( $config as $key => $value ) {
            if ( Json::SCHEMA_VERSION_KEY === $key ) {
                continue;
            }

            if ( ! in_array( $key, $allowed, true ) ) {
                return false;
            }

            // Whitelisted but not in config_schema(): a Pro trigger's own key, accepted untyped.
            $spec = is_array( $schema ) ? ( $schema[ $key ] ?? null ) : null;

            if ( null !== $spec && ! self::value_matches_spec( $value, $spec ) ) {
                return false;
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

            case 'int':
                if ( is_bool( $value ) || ! is_numeric( $value ) || floor( (float) $value ) !== (float) $value ) {
                    return false;
                }

                return ! isset( $spec['min'] ) || (float) $value >= (float) $spec['min'];

            case 'number':
                if ( is_bool( $value ) || ! is_numeric( $value ) ) {
                    return false;
                }

                if ( isset( $spec['exclusive_min'] ) && (float) $value <= (float) $spec['exclusive_min'] ) {
                    return false;
                }

                return ! isset( $spec['min'] ) || (float) $value >= (float) $spec['min'];

            case 'int_list':
                if ( ! is_array( $value ) ) {
                    return false;
                }

                foreach ( $value as $item ) {
                    if ( is_bool( $item ) || ! is_numeric( $item ) || floor( (float) $item ) !== (float) $item ) {
                        return false;
                    }
                }

                return true;

            default:
                return true;
        }
    }

    /** Whether a conditions list is acceptable to store; the controller answers 4xx on false. See CampaignConditionSet. */
    public static function conditions_are_valid( array $conditions ): bool {
        return CampaignConditionSet::is_valid( $conditions );
    }

    /** Drops config keys that do not belong to the trigger. Type checking is config_is_valid()'s job. */
    public static function validate_config( string $trigger, array $config ): array {
        $whitelist = self::config_whitelist()[ $trigger ] ?? null;

        // No whitelist means another build's trigger (Pro, on a downgraded site).
        // Filtering against nothing would erase the merchant's config on the next save.
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

    /** Default config for a built-in campaign. */
    public static function default_config( string $trigger ): array {
        switch ( $trigger ) {
            case CampaignTrigger::PURCHASE:
                return [
                    'points_per_unit'       => 1,
                    'base'                  => 'after_discounts',
                    'include_shipping'      => false,
                    'include_tax'           => false,
                    'rounding'              => 'down',
                    'min_subtotal'          => 0,
                    'exclude_coupon_orders' => false,
                    'excluded_product_ids'  => [],
                    'excluded_category_ids' => [],
                ];

            case CampaignTrigger::SIGNUP:
                return [ 'points' => 100 ];

            case CampaignTrigger::REVIEW:
                return [
                    'points'           => 50,
                    'require_verified' => true,
                    'one_per_product'  => true,
                    'daily_cap'        => 0,
                ];

            default:
                return [];
        }
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

    public function get_trigger(): string {
        return $this->trigger;
    }

    public function get_status(): string {
        return $this->status;
    }

    public function get_priority(): int {
        return $this->priority;
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

    /** UTC MySQL DATETIME of the last write. */
    public function get_updated_at(): string {
        return $this->updated_at;
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
     * be archived: the engine finds the default purchase campaign by slug.
     *
     * @throws \InvalidArgumentException When $status is unknown, or when archiving a built-in.
     */
    public function set_status( string $status ): void {
        $status = Status::assert( $status );

        if ( Status::ARCHIVED === $status && $this->is_builtin ) {
            throw new \InvalidArgumentException( 'YayLoyalty: built-in campaigns cannot be archived.' );
        }

        if ( Status::ARCHIVED === $status ) {
            if ( Status::ARCHIVED !== $this->status ) { // Set archived_at to now when it was not archived before
                $this->archived_at = Dates::now();
            }
        } else { // Every other status means the campaign is not archived
            $this->archived_at = null;
        }

        $this->status = $status;
    }

    public function set_priority( int $priority ): void {
        $this->priority = $priority;
    }

    public function set_config( array $config ): void {
        $this->config = self::validate_config( $this->trigger, $config );
    }

    /** Normalises a conditions list (see CampaignConditionSet) and stores it. Rejection is conditions_are_valid()'s job. */
    public function set_conditions( array $conditions ): void {
        $this->conditions = CampaignConditionSet::normalize( $conditions );
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
