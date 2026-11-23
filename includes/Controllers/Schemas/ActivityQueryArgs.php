<?php
namespace YayLoyalty\Controllers\Schemas;

use YayLoyalty\Enums\ActivityEvent;
use YayLoyalty\Enums\ActorKind;

defined( 'ABSPATH' ) || exit;

/**
 * The args `GET /customers/{id}/activity` takes. `search`, `from` and `to` match
 * LedgerQueryArgs' args field for field, so the two tabs' filter rows behave the same.
 *
 * `group` is a lens over the `event_type`s, not a partition: `expiring_soon_email_sent`
 * belongs to both `expiry` and `emails` on purpose, so do not "fix" the overlap.
 * `membership_started` is in no group, and `all` still shows it. `tiers` is offered only by the Pro
 * drawer; on Lite it just finds the rows a Pro install left.
 */
final class ActivityQueryArgs {

    public const GROUPS = [ 'all', 'admin', 'expiry', 'emails', 'tiers' ];

    public const ACTOR_KINDS = ActorKind::ALL;

    /** @return array<string, mixed> */
    public static function all(): array {
        return [
            'group'  => [
                'description'       => __( 'Activity group to filter by.', 'yayloyalty' ),
                'type'              => 'string',
                'enum'              => self::GROUPS,
                'default'           => 'all',
                'sanitize_callback' => 'sanitize_key',
            ],
            'search' => [
                'description'       => __( 'Free-text search over the event\'s details and who did it.', 'yayloyalty' ),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'from'   => [
                'description'       => __( 'Only events on or after this date (Y-m-d).', 'yayloyalty' ),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => [ LedgerQueryArgs::class, 'validate_date' ],
            ],
            'to'     => [
                'description'       => __( 'Only events on or before this date (Y-m-d).', 'yayloyalty' ),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => [ LedgerQueryArgs::class, 'validate_date' ],
            ],
        ];
    }

    /**
     * Never empty: `all`, like any unrecognised group, returns the full ActivityEvent vocabulary.
     *
     * @return string[]
     */
    public static function event_types_for( string $group ): array {
        switch ( $group ) {
            case 'admin':
                return [ ActivityEvent::POINTS_ADJUSTED, ActivityEvent::EXPIRY_RESET, ActivityEvent::ACCOUNT_DELETED ];
            case 'expiry':
                return [ ActivityEvent::EXPIRY_RESET, ActivityEvent::EXPIRING_SOON_EMAIL_SENT ];
            case 'emails':
                return [ ActivityEvent::BALANCE_EMAIL_SENT, ActivityEvent::EXPIRING_SOON_EMAIL_SENT ];
            case 'tiers':
                return [ ActivityEvent::TIER_CHANGED ];
            default:
                return ActivityEvent::ALL;
        }
    }
}
