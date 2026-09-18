<?php
namespace YayLoyalty\Engine\Register;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use YayLoyalty\Utils\SingletonTrait;
use YayLoyalty\Engine\Register\ScriptName;

/**
 * Picks RegisterDev (Vite dev server) or RegisterProd (built assets) by YAYLOYALTY_IS_DEVELOPMENT.
 *
 * @method static RegisterFacade get_instance()
 */
class RegisterFacade {
    use SingletonTrait;

    protected function __construct() {
        add_filter( 'script_loader_tag', [ $this, 'add_entry_as_module' ], 10, 3 );
        add_action( 'init', [ $this, 'register_all_assets' ] );
        add_filter( 'pre_load_script_translations', [ $this, 'use_mo_file_for_script_translations' ], 10, 4 );

        $is_prod = ! defined( 'YAYLOYALTY_IS_DEVELOPMENT' ) || YAYLOYALTY_IS_DEVELOPMENT !== true;
        if ( $is_prod && class_exists( '\YayLoyalty\Engine\Register\RegisterProd' ) ) {
            \YayLoyalty\Engine\Register\RegisterProd::get_instance();
        } elseif ( ! $is_prod && class_exists( '\YayLoyalty\Engine\Register\RegisterDev' ) ) {
            \YayLoyalty\Engine\Register\RegisterDev::get_instance();
        }
    }

    public function add_entry_as_module( $tag, $handle ) {
        if ( strpos( $handle, ScriptName::MODULE_PREFIX ) !== false ) {
            if ( strpos( $tag, 'type="' ) !== false ) {
                return preg_replace( '/\stype="\S+\s/', ' type="module" ', $tag, 1 );
            } else {
                return str_replace( ' src=', ' type="module" src=', $tag );
            }
        }
        return $tag;
    }

    public function register_all_assets() {
        wp_register_style(
            ScriptName::STYLE_SETTINGS,
            YAYLOYALTY_PLUGIN_URL . 'assets/dist/admin/style.css',
            [
                'woocommerce_admin_styles',
                'wp-components',
            ],
            YAYLOYALTY_VERSION
        );
    }

    /** Script translations come from the MO file, not per-handle JSON. */
    public function use_mo_file_for_script_translations( $json_translations, $file, $handle, $domain ) {
        $all_handles = [
            ScriptName::PAGE_SETTINGS,
        ];

        if ( 'yayloyalty' !== $domain || ! in_array( $handle, $all_handles, true ) ) {
            return $json_translations;
        }

        $translations = get_translations_for_domain( 'yayloyalty' );
        $messages     = [
            '' => [
                'domain' => 'messages',
            ],
        ];
        $entries      = $translations->entries;
        foreach ( $entries as $key => $entry ) {
            $messages[ $entry->singular ] = $entry->translations;
        }

        return wp_json_encode(
            [
                'domain'      => 'messages',
                'locale_data' => [
                    'messages' => $messages,
                ],
            ]
        );
    }
}
