<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Enums\ActivityEvent;
use YayLoyalty\Enums\CustomerFilters;
use YayLoyalty\Repositories\CustomerMetaRepository;
use YayLoyalty\Repositories\CustomerRepository;

defined( 'ABSPATH' ) || exit;

/**
 * `GET /customers` response shaping, apart from CustomerRestController.
 * Sibling to `CustomerRowFilters`/`CustomerRowSort`, which already own the
 * list's filter/sort semantics; this class owns the route args, the full
 * request→response handling (`respond_list()`), and the
 * wire-row/no-match-suggestion shapes built from their output.
 */
final class CustomerListFormatter {

    /**
     * Full `GET /customers` handling: filters/sorts/queries, formats rows to
     * the wire, folds in `suggestions` on a zero-row result. `$controller`
     * is only used for its inherited `respond_paginated()` envelope shape.
     *
     * @return array<string, mixed>
     */
    public static function respond_list(
        \WP_REST_Request $request,
        AbstractRestController $controller,
        CustomerRepository $customers,
        CustomerMetaRepository $customer_meta
    ): array {
        $filters = CustomerRowFilters::from_request( $request );
        $order   = CustomerRowSort::to_order(
            (string) $request->get_param( 'orderby' ),
            (string) $request->get_param( 'order' )
        );

        $result = $customers->paginate_list(
            CustomerRowFilters::to_criteria( $filters ),
            $order,
            (int) $request->get_param( 'page' ),
            (int) $request->get_param( 'per_page' )
        );

        $envelope = $controller->respond_paginated(
            [
                'items'    => array_map( fn( $row ) => self::format_row( $row, $customer_meta ), $result['items'] ),
                'total'    => $result['total'],
                'page'     => $result['page'],
                'per_page' => $result['per_page'],
            ]
        );

        if ( 0 === $result['total'] ) {
            $envelope['suggestions'] = self::no_match_suggestions( $filters, $customers );
        }

        return $envelope;
    }

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
     * `name`/`email` fall back to the `account_deleted` snapshot when the
     * `LEFT JOIN`ed `u.display_name`/`u.user_email` are `NULL` (a deleted
     * member) — display-only: the raw query's own sort/search still runs
     * against the (possibly `NULL`) joined columns, not this fallback.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function format_row( array $row, CustomerMetaRepository $customer_meta ): array {
        $user_id  = (int) $row['user_id'];
        $identity = self::identity( $user_id, $row, $customer_meta );

        return [
            'user_id'          => $user_id,
            'name'             => $identity['name'],
            'email'            => $identity['email'],
            'balance'          => (int) $row['balance'],
            'pending_points'   => (int) $row['pending_points'],
            'lifetime_earned'  => (int) $row['lifetime_earned'],
            'last_activity_at' => $row['last_activity_at'],
            'expires_at'       => $row['expires_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{name: string, email: string}
     */
    private static function identity( int $user_id, array $row, CustomerMetaRepository $customer_meta ): array {
        if ( null !== $row['name'] && null !== $row['email'] ) {
            return [
                'name'  => (string) $row['name'],
                'email' => (string) $row['email'],
            ];
        }

        $deletion = $customer_meta->latest( $user_id, ActivityEvent::ACCOUNT_DELETED );

        return null !== $deletion ? $deletion->snapshot_identity() : [
            'name'  => '',
            'email' => '',
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
