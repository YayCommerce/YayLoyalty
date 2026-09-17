<?php
namespace YayLoyalty\Models;

use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Enums\Status;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Json;

defined( 'ABSPATH' ) || exit;

/**
 * An earning rule.
 *
 * Definition table, so this is mutable — unlike LedgerEntry, a campaign is
 * edited in place. It has no service: its invariants (valid trigger, whitelisted
 * and correctly-typed config keys, derived liveness, "built-ins are not
 * archivable") are validation, and validation lives here and in the controller
 * schema. A service wrapping one repository would be ceremony. If a campaign
 * operation ever needs a second table atomically, it earns one.
 *
 * COLUMN vs FIELD: the database column is `trigger_type` (see CampaignsTable for
 * why). Everywhere else — this model's property, its getter, the REST payload —
 * the field is `trigger`. from_row()/to_row() are the single mapping point.
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
            // Model field trigger -> DB column trigger_type.
            'trigger_type' => $this->trigger,
            'status'       => $this->status,
            'priority'     => $this->priority,
            'config'       => Json::encode( $this->config, self::CONFIG_SCHEMA_VERSION ),
            // A list, not a map — see Json::encode_list().
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
     * Delegates to Status::is_live_at() so campaigns, rewards (and tiers, when
     * they land) share one implementation of the rule rather than three copies.
     */
    public function is_live_at( string $at ): bool {
        return Status::is_live_at( $this->status, $this->starts_at, $this->ends_at, $at );
    }

    /**
     * The label to show for this campaign: its stored status, or — when that is
     * `active` — `scheduled` / `ended` derived from the date window.
     *
     * $at defaults to now() because this is a display concern; pass the event's
     * timestamp to describe how a past order would have seen it.
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
     * Does this campaign's `conditions` set match the given customer?
     *
     * LITE IS DELIBERATELY PERMISSIVE. Lite stores conditions but cannot
     * evaluate them, so with no evaluator attached this returns true — a
     * campaign with conditions still matches.
     *
     * The alternative, treating unevaluatable conditions as "never match",
     * would silently disable every conditional campaign the moment a site
     * downgraded from Pro. Failing open is the lesser harm here: the
     * merchant keeps awarding points slightly too broadly rather than
     * silently stopping.
     *
     * Pro's evaluator is stricter in the opposite direction — it FAILS an
     * unknown condition type, because Pro can evaluate and an unrecognised
     * type is therefore a real error. Different reasons, different answers.
     *
     * @param int $user_id Customer being evaluated.
     */
    public function conditions_match( int $user_id ): bool {
        if ( empty( $this->conditions ) ) {
            return true;
        }

        /**
         * Evaluator for campaign `conditions`.
         *
         * Null in Lite. Pro attaches an object exposing
         * evaluate( array $conditions, int $user_id ): bool.
         *
         * Internal seam for first-party Pro code, not a documented
         * third-party API.
         *
         * @param object|null $evaluator
         */
        $evaluator = apply_filters( 'yayloyalty_campaign_conditions_evaluator', null );

        if ( null === $evaluator || ! method_exists( $evaluator, 'evaluate' ) ) {
            return true;
        }

        return (bool) $evaluator->evaluate( $this->conditions, $user_id );
    }

    /**
     * Per-trigger config schema: key => spec.
     *
     * The source of truth for what a campaign's `config` may hold, and what
     * config_is_valid() checks each value against on save.
     *
     * Covers the triggers Lite itself defines. Pro triggers — birthday,
     * first_order, order_count, spend_milestone — carry their own config
     * vocabulary, contributed through the yayloyalty_campaign_config_whitelist
     * seam (see config_whitelist()); Lite neither describes nor type-checks them.
     *
     * Spec shapes:
     *   'bool'                              — a JSON boolean
     *   'string'                            — a JSON string
     *   'int'   [ 'min' => n ]              — a whole number, optional lower bound
     *   'number'[ 'min' => n ]              — int or float, optional lower bound
     *   'enum'  [ 'values' => [ ... ] ]     — a string from a fixed set
     *   'int_list'                          — a list of whole numbers
     *
     * Pro-recognised keys (max_points_per_order, daily_cap, media_bonus_points)
     * are listed HERE IN LITE on purpose: a site that downgrades must keep its
     * stored values so an upgrade restores the campaign intact. max_points_per_order
     * and daily_cap are enforced by Engine\Points\CapPolicy in every build;
     * media_bonus_points is stored but unused. Do not "tidy" any of them out.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function config_schema(): array {
        return [
            CampaignTrigger::PURCHASE => [
                'points_per_unit'       => [
                    'type' => 'number',
                    'min'  => 0,
                ],
                // multiplier: points_per_unit of the default campaign × multiplier.
                'mode'                  => [
                    'type'   => 'enum',
                    'values' => [ 'rate', 'multiplier' ],
                ],
                'multiplier'            => [
                    'type' => 'number',
                    'min'  => 0,
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
     * Keys each trigger's config may contain, keyed by trigger.
     *
     * Derived from config_schema() so the two never drift, then passed through
     * the Pro seam. The filter is for Pro to add whitelists for triggers Lite
     * has no schema entry for at all, not to add keys to these.
     *
     * @return array<string, string[]>
     */
    public static function config_whitelist(): array {
        $whitelist = [];

        foreach ( self::config_schema() as $trigger => $keys ) {
            $whitelist[ $trigger ] = array_keys( $keys );
        }

        /**
         * Config keys each campaign trigger may store, keyed by trigger.
         *
         * Pro's attach point for its own triggers' config. Internal seam
         * for first-party Pro code, not a documented third-party API.
         *
         * @param array<string, string[]> $whitelist
         */
        return apply_filters( 'yayloyalty_campaign_config_whitelist', $whitelist );
    }

    /**
     * Is this config acceptable for this trigger?
     *
     * CampaignRestController runs this before a row is written and answers
     * 4xx on false: a malformed config would otherwise sit dormant in the
     * database and only misfire when a real order triggers it. The
     * schema_version stamp Json adds on write is ignored so a stored config
     * can be read, edited and sent back as is.
     *
     * A trigger this build has no schema and no whitelist for was created by
     * another build (a Pro campaign on a downgraded site). Lite cannot judge
     * that vocabulary, so it does not reject it — validate_config() preserves
     * the blob wholesale for the same reason.
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

            // A key the filtered whitelist allows but config_schema() does not
            // describe is a Pro trigger's own key: accept it untyped.
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

    /**
     * Are these conditions acceptable to store?
     *
     * Mirrors config_is_valid(): a non-throwing check CampaignRestController
     * runs before the row is written, answering 4xx rather than letting a bad
     * set surface later when an order is scored. Rejects an unknown type, an
     * operator a type does not accept, a wrong value shape, an empty list value,
     * a duplicate type, a missing required sub-key (currency, source), and
     * customer_ids past its cap. See Models\CampaignConditionSet.
     */
    public static function conditions_are_valid( array $conditions ): bool {
        return CampaignConditionSet::is_valid( $conditions );
    }

    /**
     * Drop config keys that do not belong to this trigger.
     *
     * Unknown keys are discarded rather than stored, so a typo cannot sit in the
     * database looking meaningful. Type checking is config_is_valid()'s job,
     * which the controller runs first; this only shapes the value that gets
     * written. schema_version is dropped here because Json re-stamps it on write.
     *
     * @param array $config
     * @return array
     */
    public static function validate_config( string $trigger, array $config ): array {
        $whitelist = self::config_whitelist()[ $trigger ] ?? null;

        // A trigger this build has no whitelist for was created by another
        // build — a Pro campaign on a site that downgraded. Lite does not know
        // that trigger's vocabulary, and "unknown" must never mean "delete":
        // filtering against an empty whitelist would silently erase the
        // merchant's configuration the first time anything re-saved the
        // campaign, and an upgrade would restore an empty campaign.
        //
        // Preserving beats validating here. Pro, which does know the
        // vocabulary, still validates its own triggers normally.
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
     * Set the stored status.
     *
     * A built-in campaign cannot be archived: the engine finds the default
     * purchase campaign by slug (CampaignRepository::DEFAULT_PURCHASE_SLUG)
     * and the Free UI lists built-ins by is_builtin/trigger, so archiving
     * would hide a row the rest of the system assumes is there.
     *
     * archived_at is kept in step with the status here — stamped on the way in,
     * cleared on the way out — so no caller has to remember to.
     *
     * @throws \InvalidArgumentException When $status is unknown, or when
     *                                   archiving a built-in.
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

    /**
     * Normalise a conditions list and store it.
     *
     * Mirrors set_config(): the value that lands on the model is the canonical
     * one — scalars widened to arrays, ids cast to int, country/currency codes
     * upper-cased, role slugs lower-cased, lists de-duplicated, entries ordered
     * cheap-first for evaluation. Rejection of a malformed set is
     * conditions_are_valid()'s job, which the controller runs first. See
     * Models\CampaignConditionSet.
     */
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
