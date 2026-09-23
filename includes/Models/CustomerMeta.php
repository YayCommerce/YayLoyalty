<?php
namespace YayLoyalty\Models;

use YayLoyalty\Enums\ActorKind;
use YayLoyalty\Utils\Json;

defined( 'ABSPATH' ) || exit;

/**
 * One `yayloyalty_customer_meta` row: a non-points customer fact, doubling
 * as one activity-log entry. `to_array()` is not the raw row — it is the
 * ACTIVITY WIRE SHAPE `ActivityRestController`'s item schema promises
 * (`id`, `occurred_at`, `event_type`, `data`, `actor_kind`, `actor_name`,
 * `staff_only`), never `schema_version`. Append-only by convention: no
 * caller ever updates or deletes a row.
 */
final class CustomerMeta extends AbstractModel {

    public const SCHEMA_VERSION = 1;

    /** @var int|null Null until inserted. */
    private $id;

    /** @var int */
    private $user_id;

    /** @var string Also the activity log's event_type. */
    private $meta_key;

    /** @var array<string, mixed> */
    private $data;

    /** @var string */
    private $actor_kind;

    /** @var string|null */
    private $actor_name;

    /** @var bool Display flag only, never access control. */
    private $staff_only;

    /** @var string Also the activity log's occurred_at. */
    private $created_at;

    public function __construct(
        ?int $id,
        int $user_id,
        string $meta_key,
        array $data,
        string $actor_kind,
        ?string $actor_name,
        bool $staff_only,
        string $created_at
    ) {
        $this->id         = $id;
        $this->user_id    = $user_id;
        $this->meta_key   = $meta_key;
        $this->data       = $data;
        $this->actor_kind = $actor_kind;
        $this->actor_name = $actor_name;
        $this->staff_only = $staff_only;
        $this->created_at = $created_at;
    }

    /** Named constructor for writers (listeners/services) — keeps call sites readable. */
    public static function event(
        int $user_id,
        string $meta_key,
        array $data,
        string $actor_kind,
        ?string $actor_name,
        bool $staff_only,
        string $created_at
    ): self {
        return new self( null, $user_id, $meta_key, $data, $actor_kind, $actor_name, $staff_only, $created_at );
    }

    /** Never throws: a corrupt `meta_value` blob degrades to an empty `data`, one row, not a 500. */
    public static function from_row( array $row ): self {
        $decoded = Json::decode( $row['meta_value'] ?? null );

        return new self(
            self::cast_int( $row['id'] ?? null ),
            self::cast_int_default( $row['user_id'] ?? 0 ),
            (string) ( $row['meta_key'] ?? '' ),
            is_array( $decoded['data'] ?? null ) ? $decoded['data'] : [],
            (string) ( $decoded['actor_kind'] ?? ActorKind::AUTOMATIC ),
            self::cast_string( $decoded['actor_name'] ?? null ),
            ! empty( $decoded['staff_only'] ),
            (string) ( $row['created_at'] ?? '' )
        );
    }

    public function to_row(): array {
        return [
            'user_id'    => $this->user_id,
            'meta_key'   => $this->meta_key,
            'meta_value' => Json::encode(
                [
                    'data'       => $this->data,
                    'actor_kind' => $this->actor_kind,
                    'actor_name' => $this->actor_name,
                    'staff_only' => $this->staff_only,
                ],
                self::SCHEMA_VERSION
            ),
            'created_at' => $this->created_at,
        ];
    }

    public function get_format(): array {
        return [ '%d', '%s', '%s', '%s' ];
    }

    /** The activity wire shape — see class docblock. Never leaks schema_version. */
    public function to_array(): array {
        return [
            'id'          => $this->id,
            'occurred_at' => $this->created_at,
            'event_type'  => $this->meta_key,
            'data'        => $this->data,
            'actor_kind'  => $this->actor_kind,
            'actor_name'  => $this->actor_name,
            'staff_only'  => $this->staff_only,
        ];
    }

    public function get_id(): ?int {
        return $this->id;
    }

    public function get_user_id(): int {
        return $this->user_id;
    }

    public function get_meta_key(): string {
        return $this->meta_key;
    }

    /** @return array<string, mixed> */
    public function get_data(): array {
        return $this->data;
    }

    /**
     * The deleted-member identity fallback both drawer formatters read —
     * the keys `account_deleted`'s snapshot writes.
     *
     * @return array{name: string, email: string}
     */
    public function snapshot_identity(): array {
        return [
            'name'  => (string) ( $this->data['display_name'] ?? '' ),
            'email' => (string) ( $this->data['email'] ?? '' ),
        ];
    }

    public function get_created_at(): string {
        return $this->created_at;
    }
}
