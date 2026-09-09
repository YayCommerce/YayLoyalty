<?php
namespace YayLoyalty\Models;

use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\Status;
use YayLoyalty\Utils\Json;

defined( 'ABSPATH' ) || exit;

/**
 * An earning rule.
 *
 * Definition table, so this is mutable — unlike LedgerEntry, a campaign is
 * edited in place. It has no service: its invariants (valid trigger, whitelisted
 * config keys, derived liveness) are validation, and validation lives here and
 * in the controller schema. A service wrapping one repository would be ceremony.
 * If a campaign operation ever needs a second table atomically, it earns one.
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

    /** @var string */
    private $created_at;

    /** @var string */
    private $updated_at;

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
        string $updated_at = ''
    ) {
        $this->id         = $id;
        $this->name       = $name;
        $this->slug       = $slug;
        $this->trigger    = $trigger;
        $this->status     = $status;
        $this->priority   = $priority;
        $this->config     = $config;
        $this->conditions = $conditions;
        $this->starts_at  = $starts_at;
        $this->ends_at    = $ends_at;
        $this->is_builtin = $is_builtin;
        $this->created_by = $created_by;
        $this->created_at = $created_at;
        $this->updated_at = $updated_at;
    }

    public static function from_row( array $row ): self {
        return new self(
            self::cast_int( $row['id'] ?? null ),
            (string) ( $row['name'] ?? '' ),
            (string) ( $row['slug'] ?? '' ),
            (string) ( $row['trigger'] ?? '' ),
            (string) ( $row['status'] ?? Status::ACTIVE ),
            self::cast_int_default( $row['priority'] ?? 0 ),
            Json::decode( $row['config'] ?? null, [] ),
            Json::decode( $row['conditions'] ?? null, [] ),
            self::cast_datetime( $row['starts_at'] ?? null ),
            self::cast_datetime( $row['ends_at'] ?? null ),
            self::cast_bool( $row['is_builtin'] ?? 0 ),
            self::cast_int( $row['created_by'] ?? null ),
            (string) ( $row['created_at'] ?? '' ),
            (string) ( $row['updated_at'] ?? '' )
        );
    }

    public function to_row(): array {
        return [
            'name'       => $this->name,
            'slug'       => $this->slug,
            // Backticked at the query layer; the key itself is the plain name.
            'trigger'    => $this->trigger,
            'status'     => $this->status,
            'priority'   => $this->priority,
            'config'     => Json::encode( $this->config, self::CONFIG_SCHEMA_VERSION ),
            'conditions' => Json::encode( $this->conditions, self::CONFIG_SCHEMA_VERSION ),
            'starts_at'  => $this->starts_at,
            'ends_at'    => $this->ends_at,
            'is_builtin' => $this->is_builtin ? 1 : 0,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    public function get_format(): array {
        return [ '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ];
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
     * Is this campaign live at a given instant?
     *
     * $at is the EVENT's timestamp — an order's award time — never now(). A
     * late-processed order must be scored under the rules that were live when
     * it happened.
     *
     * Delegates to Status::is_live_at() so campaigns, rewards and tiers share
     * one implementation of the rule rather than three copies.
     */
    public function is_live_at( string $at ): bool {
        return Status::is_live_at( $this->status, $this->starts_at, $this->ends_at, $at );
    }

    /**
     * Keys each trigger's config may contain.
     *
     * Pro-only keys are listed and preserved: a site that downgrades must keep
     * its stored values so an upgrade restores the campaign intact. They are
     * simply not acted on in Free.
     *
     * @return array<string, string[]>
     */
    private static function config_whitelist(): array {
        return [
            CampaignTrigger::PURCHASE => [
                'points_per_unit',
                'base',                  // after_discounts | before_discounts
                'rounding',              // down | nearest | up
                'min_subtotal',
                'exclude_coupon_orders',
                'excluded_product_ids',
                'excluded_category_ids',
                // Pro
                'max_points_per_order',
                'daily_cap',
            ],
            CampaignTrigger::SIGNUP   => [
                'points',
            ],
            CampaignTrigger::REVIEW   => [
                'points',
                'require_verified',
                'one_per_product',
                'daily_cap',
                // Pro
                'media_bonus_points',
            ],
        ];
    }

    /**
     * Drop config keys that do not belong to this trigger.
     *
     * Unknown keys are discarded rather than stored, so a typo cannot sit in the
     * database looking meaningful. schema_version is preserved because Json
     * stamps it on write.
     *
     * @param array $config
     * @return array
     */
    public static function validate_config( string $trigger, array $config ): array {
        $whitelist = self::config_whitelist()[ $trigger ] ?? [];

        $clean = [];

        foreach ( $whitelist as $key ) {
            if ( array_key_exists( $key, $config ) ) {
                $clean[ $key ] = $config[ $key ];
            }
        }

        return $clean;
    }

    /**
     * Default config for a built-in campaign.
     *
     * @return array
     */
    public static function default_config( string $trigger ): array {
        switch ( $trigger ) {
            case CampaignTrigger::PURCHASE:
                return [
                    'points_per_unit'       => 1,
                    'base'                  => 'after_discounts',
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

    public function is_builtin(): bool {
        return $this->is_builtin;
    }

    public function set_name( string $name ): void {
        $this->name = $name;
    }

    public function set_status( string $status ): void {
        $this->status = Status::assert( $status );
    }

    public function set_priority( int $priority ): void {
        $this->priority = $priority;
    }

    public function set_config( array $config ): void {
        $this->config = self::validate_config( $this->trigger, $config );
    }

    public function set_conditions( array $conditions ): void {
        $this->conditions = $conditions;
    }

    public function set_starts_at( ?string $starts_at ): void {
        $this->starts_at = $starts_at;
    }

    public function set_ends_at( ?string $ends_at ): void {
        $this->ends_at = $ends_at;
    }

    public function set_updated_at( string $updated_at ): void {
        $this->updated_at = $updated_at;
    }
}
