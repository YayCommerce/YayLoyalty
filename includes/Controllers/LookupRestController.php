<?php
namespace YayLoyalty\Controllers;

use YayLoyalty\Access\Capabilities;
use YayLoyalty\Utils\Text;

defined( 'ABSPATH' ) || exit;

/**
 * Name lookups for the admin's pickers: products, product categories and
 * customers, by search term or by id to label a stored selection. Every
 * editor that names one of these shares it, so it is a resource of its own,
 * not a campaign sub-route. Read-only, capped at LIMIT rows, and behind VIEW
 * like the customers list, which already shows the same emails.
 */
final class LookupRestController extends AbstractRestController {

    public const ENTITIES = [ 'products', 'categories', 'customers' ];

    /** Rows per answer. A picker lists, it does not page. */
    private const LIMIT = 20;

    public function get_rest_base(): string {
        return 'lookups';
    }

    public function register_routes(): void {
        register_rest_route(
            self::REST_NAMESPACE,
            '/lookups/(?P<entity>' . implode( '|', self::ENTITIES ) . ')',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => fn( $request ) => $this->exec_read( [ $this, 'lookup' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::VIEW ),
                    'args'                => [
                        'search' => [
                            'type'              => 'string',
                            'default'           => '',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        // Comma-separated on the wire, and WordPress splits it. Wins over `search` when present.
                        'ids'    => [
                            'type'    => 'array',
                            'items'   => [ 'type' => 'integer' ],
                            'default' => [],
                        ],
                    ],
                ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/lookups/products/(?P<id>[\d]+)',
            [
                [
                    'methods'             => \WP_REST_Server::READABLE,
                    'callback'            => fn( $request ) => $this->exec_read( [ $this, 'product_summary' ], $request ),
                    'permission_callback' => $this->requires( Capabilities::VIEW ),
                ],
            ]
        );
    }

    /**
     * One product's card: name, SKU, price, categories, thumbnail and live
     * stock, for an editor that shows what a stored product id points at.
     * 404 once the product is gone or trashed.
     *
     * @return array{id: int, name: string, sku: string, price: string, category_names: string, image_url: string|null, stock_status: string, stock_quantity: int|null}|\WP_Error
     */
    public function product_summary( \WP_REST_Request $request ) {
        $product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $request['id'] ) : null;

        // A trashed product is "deleted" to the merchant; showing it would hide that the reward is broken.
        if ( ! $product || 'trash' === $product->get_status() ) {
            return $this->error_not_found( __( 'Product not found.', 'yayloyalty' ) );
        }

        $image_id  = (int) $product->get_image_id();
        $image_url = $image_id > 0 ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : false;
        $quantity  = $product->managing_stock() ? $product->get_stock_quantity() : null;

        return [
            'id'             => (int) $product->get_id(),
            'name'           => Text::plain( $product->get_name() ),
            'sku'            => (string) $product->get_sku(),
            'price'          => '' === (string) $product->get_price() ? '' : Text::plain( wc_price( (float) $product->get_price() ) ),
            'category_names' => Text::plain( (string) wc_get_product_category_list( $product->get_id(), ', ' ) ),
            'image_url'      => false === $image_url ? null : $image_url,
            'stock_status'   => (string) $product->get_stock_status(),
            // Null when WooCommerce tracks no count for it: the status is all there is.
            'stock_quantity' => null === $quantity ? null : (int) $quantity,
        ];
    }

    /** @return array{items: array<int, array{id: int, label: string}>} */
    public function lookup( \WP_REST_Request $request ): array {
        $entity = (string) $request['entity'];
        $search = trim( (string) $request['search'] );
        $ids    = [];

        foreach ( (array) $request['ids'] as $raw_id ) {
            if ( (int) $raw_id > 0 ) {
                $ids[] = (int) $raw_id;
            }
        }

        switch ( $entity ) {
            case 'products':
                $items = $this->products( $ids, $search );
                break;
            case 'categories':
                $items = $this->categories( $ids, $search );
                break;
            default:
                $items = $this->customers( $ids, $search );
        }

        return [ 'items' => $items ];
    }

    /**
     * Parent products only, published when searching: the conditions evaluator reports a bought
     * variation under its parent (Pro\Services\ConditionsEvaluator::purchased_product_ids()).
     *
     * @param int[] $ids
     * @return array<int, array{id: int, label: string}>
     */
    private function products( array $ids, string $search ): array {
        if ( ! function_exists( 'wc_get_product' ) ) {
            return [];
        }

        if ( [] === $ids ) {
            $ids = '' === $search
                ? wc_get_products(
                    [
                        'limit'   => self::LIMIT,
                        'status'  => 'publish',
                        'orderby' => 'title',
                        'order'   => 'ASC',
                        'return'  => 'ids',
                    ]
                )
                : \WC_Data_Store::load( 'product' )->search_products( $search, '', false, false, self::LIMIT );
        }

        $items = [];

        foreach ( $ids as $id ) {
            $product = wc_get_product( (int) $id );

            if ( $product ) {
                $items[] = $this->item( (int) $product->get_id(), $product->get_formatted_name() );
            }
        }

        return $items;
    }

    /**
     * @param int[] $ids
     * @return array<int, array{id: int, label: string}>
     */
    private function categories( array $ids, string $search ): array {
        $args = [
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'orderby'    => 'name',
            'number'     => self::LIMIT,
        ];

        if ( [] !== $ids ) {
            $args['include'] = $ids;
            $args['number']  = count( $ids );
        } elseif ( '' !== $search ) {
            $args['search'] = $search;
        }

        $terms = get_terms( $args );

        if ( is_wp_error( $terms ) ) {
            return [];
        }

        return array_map( fn( \WP_Term $term ) => $this->item( (int) $term->term_id, $term->name ), $terms );
    }

    /**
     * @param int[] $ids
     * @return array<int, array{id: int, label: string}>
     */
    private function customers( array $ids, string $search ): array {
        $args = [
            'number'  => self::LIMIT,
            'orderby' => 'display_name',
            'fields'  => [ 'ID', 'display_name', 'user_email' ],
        ];

        if ( [] !== $ids ) {
            $args['include'] = $ids;
            $args['number']  = count( $ids );
        } elseif ( '' !== $search ) {
            $args['search']         = '*' . $search . '*';
            $args['search_columns'] = [ 'user_login', 'user_email', 'display_name', 'user_nicename' ];
        }

        return array_map(
            fn( $user ) => $this->item( (int) $user->ID, sprintf( '%s (%s)', $user->display_name, $user->user_email ) ),
            ( new \WP_User_Query( $args ) )->get_results()
        );
    }

    /** @return array{id: int, label: string} */
    private function item( int $id, string $label ): array {
        return [
            'id'    => $id,
            'label' => Text::plain( $label ),
        ];
    }

    /**
     * An empty item schema: this returns picker rows, not a resource.
     *
     * @return array<string, mixed>
     */
    protected function define_item_schema(): array {
        return [
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'yayloyalty_lookup',
            'type'       => 'object',
            'properties' => [],
        ];
    }
}
