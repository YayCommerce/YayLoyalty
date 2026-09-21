<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Enums\CustomerFilters;
use YayLoyalty\Repositories\CustomerRepository;

defined( 'ABSPATH' ) || exit;

/**
 * `GET /customers` response shaping — split out of CustomerRestController to
 * keep it near the 200-line cap. Sibling to `CustomerRowFilters`/
 * `CustomerRowSort`, which already own the list's filter/sort semantics;
 * this class owns the route args and the wire-row/no-match-suggestion shapes
 * built from their output. `get_items()` stays on the controller — the
 * class docblock there already explains why read-side composition lives on
 * the controller rather than a service.
 */
final class CustomerListFormatter {

    /**
     * The Customers-list-only search/filter params — `search` already lives on
     * `AbstractRestController::get_collection_params()`, so only the
     * customer-specific ones are added here. Values/enums mirror
     * `customers-page.filter-rows.ts` exactly.
     *
     * @return array<string, mixed>
     */
    public static function filter_params(): array {
        return [
            'balance_range' => [
                'description'       => __( 'Balance bucket to filter by.', 'yayloyalty' ),
                'type'              => 'string',
                'enum'              => CustomerFilters::BALANCE_RANGES,
                'default'           => CustomerFilters::BALANCE_RANGES[0],
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'expiring_soon' => [
                'description'       => __( 'Points-expiry window to filter by.', 'yayloyalty' ),
                'type'              => 'string',
                'enum'              => CustomerFilters::EXPIRY_RANGES,
                'default'           => CustomerFilters::EXPIRY_RANGES[0],
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'tier'          => [
                'description'       => __( 'Loyalty tier to filter by (no tiers exist yet — always matches zero rows).', 'yayloyalty' ),
                'type'              => 'string',
                'enum'              => CustomerFilters::TIER_RANGES,
                'default'           => CustomerFilters::TIER_RANGES[0],
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
    }

    /**
     * One list row — deliberately not `Models\Customer::to_array()`: this is
     * a JOIN projection carrying `name`/`email`, which the customer entity
     * has no place for. `$wpdb` returns every column as a string, so casting
     * here is mandatory (the admin app parses with zod `z.number().int()`,
     * which rejects `"640"`).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function format_row( array $row ): array {
        return [
            'user_id'          => (int) $row['user_id'],
            'name'             => (string) $row['name'],
            'email'            => (string) $row['email'],
            'balance'          => (int) $row['balance'],
            'pending_points'   => (int) $row['pending_points'],
            'lifetime_earned'  => (int) $row['lifetime_earned'],
            'last_activity_at' => $row['last_activity_at'],
            'expires_at'       => $row['expires_at'],
        ];
    }

    /**
     * "Which filter should I drop?" — one `count_list()` call per candidate
     * `CustomerRowFilters::candidates_for_no_match()` builds, kept only when
     * non-zero and sorted by count descending.
     *
     * @param array<string, string> $filters
     * @return array<int, array{key: string, count: int}>
     */
    public static function no_match_suggestions( array $filters, CustomerRepository $customers ): array {
        $suggestions = [];

        foreach ( CustomerRowFilters::candidates_for_no_match( $filters ) as $key => $candidate ) {
            $count = $customers->count_list( CustomerRowFilters::to_criteria( $candidate ) );

            if ( $count > 0 ) {
                $suggestions[] = [
                    'key'   => $key,
                    'count' => $count,
                ];
            }
        }

        usort( $suggestions, fn( $a, $b ) => $b['count'] <=> $a['count'] );

        return $suggestions;
    }
}
