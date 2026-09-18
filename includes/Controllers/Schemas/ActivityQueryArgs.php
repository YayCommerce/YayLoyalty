<?php
namespace YayLoyalty\Controllers\Schemas;

defined( 'ABSPATH' ) || exit;

/**
 * The args `GET /customers/{id}/activity` takes — split out of
 * `ActivityRestController` the way `LedgerQueryArgs` is split out of
 * `LedgerRestController`. `search`/`after`/`before` mirror `LedgerQueryArgs`'
 * own args field-for-field (same descriptions, same `validate_date`), so the
 * two tabs' filter rows behave identically from the admin's side.
 *
 * `group` is a GROUPING over the seven `event_type`s
 * (`DrawerActivity::EVENT_TYPES`), not a partition: `expiring_soon_email_sent`
 * belongs to BOTH `expiry` and `emails` on purpose — it is a lens ("what am I
 * looking for"), not a mutually-exclusive category. Do not "fix" the overlap.
 */
final class ActivityQueryArgs {

    public const GROUPS = [ 'all', 'admin', 'tier', 'expiry', 'emails' ];

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
     * `null` for `all` — the caller skips filtering entirely rather than
     * matching against a redundant full list.
     *
     * @return string[]|null
     */
    public static function event_types_for( string $group ): ?array {
        switch ( $group ) {
            case 'admin':
                return [ 'points_adjusted', 'expiry_reset' ];
            case 'tier':
                return [ 'tier_changed' ];
            case 'expiry':
                return [ 'expiry_reset', 'expiring_soon_email_sent' ];
            case 'emails':
                return [ 'balance_email_sent', 'expiring_soon_email_sent' ];
            default:
                return null;
        }
    }
}
