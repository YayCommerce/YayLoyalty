<?php
namespace YayLoyalty\Controllers\Schemas;

use YayLoyalty\Enums\ActivityEvent;
use YayLoyalty\Enums\ActorKind;

defined( 'ABSPATH' ) || exit;

/**
 * The args `GET /customers/{id}/activity` takes — split out of
 * `ActivityRestController` the way `LedgerQueryArgs` is split out of
 * `LedgerRestController`. `search`/`after`/`before` mirror `LedgerQueryArgs`'
 * own args field-for-field (same descriptions, same `validate_date`), so the
 * two tabs' filter rows behave identically from the admin's side.
 *
 * `group` is a GROUPING over the eight `event_type`s (`ActivityEvent::ALL`), not a
 * partition: `expiring_soon_email_sent` belongs to BOTH `expiry` and `emails`
 * on purpose — it is a lens ("what am I looking for"), not a mutually-exclusive
 * category. Do not "fix" the overlap. `account_created` is in no group;
 * `all` still shows it.
 */
final class ActivityQueryArgs {

    public const GROUPS = [ 'all', 'admin', 'tier', 'expiry', 'emails' ];

    public const ACTOR_KINDS = ActorKind::ALL;

    /**
     * @return array<string, mixed>
     */
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
            'after'  => [
                'description'       => __( 'Only events on or after this date (Y-m-d).', 'yayloyalty' ),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => [ LedgerQueryArgs::class, 'validate_date' ],
            ],
            'before' => [
                'description'       => __( 'Only events on or before this date (Y-m-d).', 'yayloyalty' ),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => [ LedgerQueryArgs::class, 'validate_date' ],
            ],
        ];
    }

    /**
     * Never empty: `all` (and any unrecognised group) returns the full ActivityEvent vocabulary.
     *
     * @return string[]
     */
    public static function event_types_for( string $group ): array {
        switch ( $group ) {
            case 'admin':
                return [ ActivityEvent::POINTS_ADJUSTED, ActivityEvent::EXPIRY_RESET, ActivityEvent::ACCOUNT_DELETED ];
            case 'tier':
                return [ ActivityEvent::TIER_CHANGED ];
            case 'expiry':
                return [ ActivityEvent::EXPIRY_RESET, ActivityEvent::EXPIRING_SOON_EMAIL_SENT ];
            case 'emails':
                return [ ActivityEvent::BALANCE_EMAIL_SENT, ActivityEvent::EXPIRING_SOON_EMAIL_SENT ];
            default:
                return ActivityEvent::ALL;
        }
    }
}
