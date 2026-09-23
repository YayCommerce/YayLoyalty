<?php
namespace YayLoyalty\Controllers\Schemas;

defined( 'ABSPATH' ) || exit;

/**
 * The two small route-arg fragments `CustomerRestController` needs beyond
 * `CustomerListFormatter::filter_params()` — split out to keep that
 * controller under the 200-line cap, the same reason `ActivityQueryArgs`/
 * `LedgerQueryArgs` exist.
 */
final class CustomerRouteArgs {

    /**
     * Overrides `get_collection_params()`'s free-text `orderby` with the six
     * real sort keys the Customers list understands. See `CustomerRowSort`.
     *
     * @return array<string, mixed>
     */
    public static function list_sort(): array {
        return [
            'orderby' => [
                'description'       => __( 'Column to sort the Customers list by.', 'yayloyalty' ),
                'type'              => 'string',
                'enum'              => [ 'name', 'balance', 'pending', 'lifetime', 'last_activity', 'expires' ],
                'default'           => 'balance',
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
            'points' => [
                'description' => __( 'Signed number of points to grant or deduct.', 'yayloyalty' ),
                'type'        => 'integer',
                'required'    => true,
            ],
            'note'   => [
                'description'       => __( 'Reason for the adjustment, shown in history.', 'yayloyalty' ),
                'type'              => 'string',
                'required'          => true,
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
    }
}
