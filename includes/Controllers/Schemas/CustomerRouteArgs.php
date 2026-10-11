<?php
namespace YayLoyalty\Controllers\Schemas;

use YayLoyalty\Controllers\CustomerRowSort;

defined( 'ABSPATH' ) || exit;

/** Route args for the Customers list's sort and for the adjust endpoint. */
final class CustomerRouteArgs {

    /**
     * Replaces get_collection_params()'s free-text `orderby` with the sort keys
     * CustomerRowSort accepts.
     *
     * @return array<string, mixed>
     */
    public static function list_sort(): array {
        return [
            'orderby' => [
                'description'       => __( 'Column to sort the Customers list by.', 'yayloyalty' ),
                'type'              => 'string',
                'enum'              => CustomerRowSort::KEYS,
                'default'           => CustomerRowSort::DEFAULT_COLUMN,
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
    }

    /**
     * `POST /customers/{id}/adjust` body args.
     *
     * @return array<string, mixed>
     */
    public static function adjust(): array {
        return [
            'points'          => [
                'description' => __( 'Signed number of points to grant or deduct.', 'yayloyalty' ),
                'type'        => 'integer',
                'required'    => true,
            ],
            'note'            => [
                'description'       => __( 'Reason for the adjustment, shown in history.', 'yayloyalty' ),
                'type'              => 'string',
                'required'          => true,
                'sanitize_callback' => 'sanitize_text_field',
            ],
            // On moves lifetime_earned, and so tier progress, by the same signed amount in either
            // direction. Off writes a balance-only `correction` row.
            'adjust_lifetime' => [
                'description' => __( 'Whether this adjustment also moves lifetime_earned, not the balance only.', 'yayloyalty' ),
                'type'        => 'boolean',
                'default'     => true,
            ],
            'notify'          => [
                'description' => __( 'Whether to email the customer about this adjustment.', 'yayloyalty' ),
                'type'        => 'boolean',
                'default'     => false,
            ],
        ];
    }
}
