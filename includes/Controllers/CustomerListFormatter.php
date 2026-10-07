<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Enums\ActivityEvent;
use YayLoyalty\Enums\CustomerFilters;
use YayLoyalty\Repositories\CustomerMetaRepository;
use YayLoyalty\Repositories\CustomerRepository;

defined( 'ABSPATH' ) || exit;

/**
 * `GET /customers` handling: the route args, the request-to-response flow in respond_list(), and the
 * wire rows and no-match suggestions. CustomerRowFilters and CustomerRowSort own the filter and sort rules.
 */
final class CustomerListFormatter {

    /**
     * Filters, sorts and queries, formats rows for the wire, and adds
     * `suggestions` to a zero-row result. `$controller` only supplies the
     * respond_paginated() envelope.
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
     * The Customers list's own filter params. `search` already comes from
     * AbstractRestController::get_collection_params().
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
        ];
    }

    /**
     * One list row. Not Models\Customer::to_array(): this is a JOIN projection
     * carrying `name` and `email`, which the entity has no place for. $wpdb
     * returns every column as a string, so the casts are required: the admin
     * app parses with zod `z.number().int()`, which rejects `"640"`.
     *
     * `name` and `email` fall back to the `account_deleted` snapshot when the
     * joined columns are NULL, for a deleted member. The fallback is for display
     * only: the query's sort and search still run on the joined columns.
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
     * "Which filter should I drop?": one count_list() call per candidate from
     * CustomerRowFilters::candidates_for_no_match(), kept when non-zero and
     * sorted by count, highest first.
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
