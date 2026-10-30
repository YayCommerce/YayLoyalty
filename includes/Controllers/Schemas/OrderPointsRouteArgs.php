<?php
namespace YayLoyalty\Controllers\Schemas;

defined( 'ABSPATH' ) || exit;

/** Route args for `POST /orders/{id}/points`. */
final class OrderPointsRouteArgs {

    /**
     * `POST /orders/{id}/points` body args. Points are read only when nothing says how many are missing.
     *
     * @return array<string, mixed>
     */
    public static function recover(): array {
        return [
            'points' => [
                'description' => __( 'Points to give back, when the order has no known figure.', 'yayloyalty' ),
                'type'        => 'integer',
                'minimum'     => 1,
                'maximum'     => 1000000,
                'required'    => false,
            ],
            // A custom sanitize_callback replaces the default that enforces maxLength, so validate explicitly.
            'note'   => [
                'description'       => __( 'Reason for the recovery, shown in history and the order note.', 'yayloyalty' ),
                'type'              => 'string',
                'maxLength'         => 500,
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => '',
            ],
        ];
    }
}
