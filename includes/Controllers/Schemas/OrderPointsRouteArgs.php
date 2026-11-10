<?php
namespace YayLoyalty\Controllers\Schemas;

defined( 'ABSPATH' ) || exit;

/** Route args for `POST /orders/{id}/grant-points`. */
final class OrderPointsRouteArgs {

    /**
     * Body args, with the order id.
     *
     * @return array<string, mixed>
     */
    public static function grant(): array {
        return [
            'id'     => [
                'description' => __( 'WooCommerce order id.', 'yayloyalty' ),
                'type'        => 'integer',
                'minimum'     => 1,
                'required'    => true,
            ],
            'points' => [
                'description' => __( 'Points to grant for the order.', 'yayloyalty' ),
                'type'        => 'integer',
                'minimum'     => 1,
                'required'    => true,
            ],
            // A custom sanitize_callback replaces the default that enforces maxLength, so validate explicitly.
            'note'   => [
                'description'       => __( 'Reason for the grant, added to the order note.', 'yayloyalty' ),
                'type'              => 'string',
                'default'           => '',
                'maxLength'         => 200,
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'notify' => [
                'description' => __( 'Whether to send the customer the Points earned email.', 'yayloyalty' ),
                'type'        => 'boolean',
                'default'     => false,
            ],
        ];
    }
}
