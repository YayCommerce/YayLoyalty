<?php
namespace YayLoyalty\Controllers\Schemas;

defined( 'ABSPATH' ) || exit;

/** Route args for Tools → Import balances: `tools/import/preview` and `tools/import/apply`. */
final class ToolsImportRouteArgs {

    private const PREVIEW_MAX_ROWS = 500;

    private const APPLY_MAX_ROWS = 100;

    /**
     * `POST /tools/import/preview` body args.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function preview(): array {
        return self::rows( self::PREVIEW_MAX_ROWS );
    }

    /**
     * `POST /tools/import/apply` body args.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function apply(): array {
        return self::rows( self::APPLY_MAX_ROWS ) + [
            'file_name' => [
                'type'              => 'string',
                'maxLength'         => 100,
                'default'           => '',
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_file_name',
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function rows( int $max_rows ): array {
        return [
            'import_id' => [
                'type'     => 'integer',
                'minimum'  => 1,
                'required' => true,
            ],
            'rows'      => [
                'type'     => 'array',
                'required' => true,
                'maxItems' => $max_rows,
                'items'    => [
                    'type'       => 'object',
                    'properties' => [
                        'row'    => [
                            'type'    => 'integer',
                            'minimum' => 1,
                        ],
                        'email'  => [ 'type' => 'string' ],
                        'points' => [ 'type' => 'string' ],
                        'note'   => [ 'type' => 'string' ],
                    ],
                ],
            ],
        ];
    }
}
