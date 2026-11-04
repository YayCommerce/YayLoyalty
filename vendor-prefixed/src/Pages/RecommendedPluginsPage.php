<?php

namespace YayLoyaltyScoped\YayCommerce\AdminShell\Pages;

/**
 * Renders the "Other Plugins" admin page and handles AJAX actions.
 * Ported from YayMail Pro OtherPluginsMenu.php — de-branded.
 *
 * NOTE: Exceeds 200 LOC because three AJAX handlers (get/activate/upgrade) each
 * require independent error handling + WP upgrader integration. Splitting them
 * into separate classes would fragment cohesive plugin-management logic.
 * Acceptable exception per code standards.
 */
class RecommendedPluginsPage
{
    protected static ?self $instance = null;
    public static function get_instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    protected function __construct()
    {
        add_action('wp_ajax_yay_recommended_get_plugin_data', [$this, 'ajax_get_plugin_data'], 9);
        add_action('wp_ajax_yay_recommended_activate_plugin', [$this, 'ajax_activate_plugin'], 9);
        add_action('wp_ajax_yay_recommended_upgrade_plugin', [$this, 'ajax_upgrade_plugin'], 9);
    }
    public static function render(): void
    {
        if (function_exists('YayLoyaltyScoped\WC')) {
            $featured_tab = '<li class="plugin-install-tab plugin-install-featured" data-tab="featured"><a href="#">Featured</a></li>';
            $woo_tab = '<li class="plugin-install-tab plugin-install-woocommerce" data-tab="woocommerce"><a href="#" class="current" aria-current="page">WooCommerce</a></li>';
        } else {
            $featured_tab = '<li class="plugin-install-tab plugin-install-featured" data-tab="featured"><a href="#" class="current" aria-current="page">Featured</a></li>';
            $woo_tab = '<li class="plugin-install-tab plugin-install-woocommerce" data-tab="woocommerce"><a href="#">WooCommerce</a></li>';
        }
        ?>
        <script>
            document.querySelector("#wpbody-content").innerHTML = "";
        </script>
        <div class="wrap">
            <div class="yay-recommended-plugins-layout">
                <div class="yay-recommended-plugins-layout-header">
                    <div class="wp-filter yay-recommended-plugins-header">
                        <h2 class="yay-recommended-plugins-header-title"><?php 
        esc_attr_e('Recommended Plugins', 'yaycommerce');
        ?></h2>
                        <ul class="filter-links">
                            <?php 
        echo wp_kses_post($featured_tab);
        ?>
                            <li class="plugin-install-tab plugin-install-all" data-tab="all"><a href="#">All</a></li>
                            <?php 
        echo wp_kses_post($woo_tab);
        ?>
                            <li class="plugin-install-tab plugin-install-management" data-tab="management"><a href="#">Management</a></li>
                            <li class="plugin-install-tab plugin-install-marketing" data-tab="marketing"><a href="#">Marketing</a></li>
                        </ul>
                    </div>
                </div>
                <div class="wp-list-table widefat plugin-install">
                    <div id="the-list"></div>
                </div>
            </div>
        </div>
        <?php 
    }
    public static function load_data(): void
    {
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_scripts']);
    }
    public static function enqueue_scripts(): void
    {
        $assets_url = plugin_dir_url(__FILE__) . '../../assets/';
        wp_enqueue_script('plugin-install');
        wp_enqueue_script('thickbox');
        wp_enqueue_style('thickbox');
        wp_enqueue_style('yaycommerce-other-plugins', $assets_url . 'css/other-plugins.css', [], '1.0');
        wp_register_script('yaycommerce-other-plugins', $assets_url . 'js/other-plugins.js', ['jquery'], '1.0', \true);
        wp_localize_script('yaycommerce-other-plugins', 'yayRecommended', ['nonce' => wp_create_nonce('yay_recommended_nonce'), 'admin_ajax' => admin_url('admin-ajax.php'), 'woo_active' => function_exists('YayLoyaltyScoped\WC')]);
        wp_enqueue_script('yaycommerce-other-plugins');
    }
    /**
     * Return the plugins to display. Filterable via yay_recommended_plugins_excluded.
     *
     * @return array<string, array>
     */
    public static function get_other_plugins(): array
    {
        return include __DIR__ . '/../../views/recommended-plugins-data.php';
    }
    public function ajax_get_plugin_data(): void
    {
        try {
            if (!current_user_can('install_plugins')) {
                wp_send_json_error(['mess' => __('Permission denied', 'yaycommerce')]);
            }
            if (!isset($_POST['tab'])) {
                wp_send_json_error(['mess' => 'Missing tab']);
            }
            $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
            if (!wp_verify_nonce($nonce, 'yay_recommended_nonce')) {
                wp_send_json_error(['mess' => __('Nonce is invalid', 'yaycommerce')]);
            }
            require_once \ABSPATH . 'wp-admin/includes/plugin-install.php';
            $tab = sanitize_text_field($_POST['tab']);
            $recommended_data = apply_filters('yay_recommended_plugins_excluded', self::get_other_plugins());
            $recommended_plugins = [];
            foreach ($recommended_data as $key => $plugin) {
                if (in_array($tab, $plugin['type'], \true) || 'all' === $tab) {
                    $recommended_plugins[$key] = $plugin;
                }
            }
            ob_start();
            include __DIR__ . '/../../views/recommended-plugins-page.php';
            $html = ob_get_clean();
            wp_send_json_success(['mess' => __('Get data success', 'yaycommerce'), 'html' => $html]);
        } catch (\Exception $ex) {
            wp_send_json_error(['mess' => __('Error exception.', 'yaycommerce'), ['error' => $ex]]);
        } catch (\Error $ex) {
            wp_send_json_error(['mess' => __('Error.', 'yaycommerce'), ['error' => $ex]]);
        }
    }
    public function ajax_activate_plugin(): void
    {
        try {
            if (!current_user_can('activate_plugins')) {
                wp_send_json_error(['mess' => __('Permission denied', 'yaycommerce')]);
            }
            if (!isset($_POST['file'])) {
                wp_send_json_error(['mess' => 'Missing file']);
            }
            $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
            if (!wp_verify_nonce($nonce, 'yay_recommended_nonce')) {
                wp_send_json_error(['mess' => __('Nonce is invalid', 'yaycommerce')]);
            }
            $file = sanitize_text_field($_POST['file']);
            $result = activate_plugin($file);
            if (is_wp_error($result)) {
                wp_send_json_error(['mess' => $result->get_error_message()]);
            }
            wp_send_json_success(['mess' => __('Activate success', 'yaycommerce')]);
        } catch (\Exception $ex) {
            wp_send_json_error(['mess' => __('Error exception.', 'yaycommerce'), ['error' => $ex]]);
        } catch (\Error $ex) {
            wp_send_json_error(['mess' => __('Error.', 'yaycommerce'), ['error' => $ex]]);
        }
    }
    public function ajax_upgrade_plugin(): void
    {
        try {
            if (!current_user_can('install_plugins')) {
                wp_send_json_error(['mess' => __('Permission denied', 'yaycommerce')]);
            }
            require_once \ABSPATH . 'wp-admin/includes/plugin-install.php';
            require_once \ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            require_once \ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
            require_once \ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
            if (!isset($_POST['plugin'])) {
                wp_send_json_error(['mess' => 'Missing plugin']);
            }
            $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
            if (!wp_verify_nonce($nonce, 'yay_recommended_nonce')) {
                wp_send_json_error(['mess' => __('Nonce is invalid', 'yaycommerce')]);
            }
            $plugin = sanitize_text_field($_POST['plugin']);
            $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'install';
            $skin = new \WP_Ajax_Upgrader_Skin();
            $upgrader = new \Plugin_Upgrader($skin);
            if ('install' === $type) {
                $result = $upgrader->install($plugin);
                if (is_wp_error($result)) {
                    wp_send_json_error(['mess' => $result->get_error_message()]);
                }
                $args = ['slug' => $upgrader->result['destination_name'], 'fields' => ['short_description' => \true, 'icons' => \true, 'banners' => \false, 'reviews' => \false, 'sections' => \false]];
                $plugin_data = plugins_api('plugin_information', $args);
                if ($plugin_data && !is_wp_error($plugin_data)) {
                    $install_status = install_plugin_install_status($plugin_data);
                    $active_plugin = activate_plugin($install_status['file']);
                    if (is_wp_error($active_plugin)) {
                        wp_send_json_error(['mess' => $active_plugin->get_error_message()]);
                    }
                    wp_send_json_success(['mess' => __('Install success', 'yaycommerce')]);
                } else {
                    wp_send_json_error(['mess' => 'Error']);
                }
            } else {
                $is_active = is_plugin_active($plugin);
                $result = $upgrader->upgrade($plugin);
                if (is_wp_error($result)) {
                    wp_send_json_error(['mess' => $result->get_error_message()]);
                }
                activate_plugin($plugin);
                wp_send_json_success(['mess' => __('Update success', 'yaycommerce'), 'active' => $is_active]);
            }
        } catch (\Exception $ex) {
            wp_send_json_error(['mess' => __('Error exception.', 'yaycommerce'), ['error' => $ex]]);
        } catch (\Error $ex) {
            wp_send_json_error(['mess' => __('Error.', 'yaycommerce'), ['error' => $ex]]);
        }
    }
    public function check_pro_version_exists(array $plugin_detail): ?string
    {
        $all_plugins = get_plugins();
        $slug = $plugin_detail['slug'] ?? '';
        $map = ['filebird' => ['filebird-pro/filebird.php'], 'yaymail' => ['yaymail-pro/yaymail.php', 'email-customizer-for-woocommerce/yaymail.php'], 'yaycurrency' => ['yaycurrency-pro/yay-currency.php', 'multi-currency-switcher/yay-currency.php'], 'yaysmtp' => ['yaysmtp-pro/yay-smtp.php'], 'yayswatches' => ['yayswatches-pro/yay-swatches.php'], 'yayextra' => ['yayextra-pro/yayextra.php'], 'yaypricing' => ['yaypricing-pro/yaypricing.php', 'dynamic-pricing-discounts/yaypricing.php'], 'yay-customer-reviews-woocommerce' => ['yayreviews-pro/yay-customer-reviews-woocommerce.php'], 'yay-wholesale-b2b' => ['yay-wholesale-b2b-pro/yay-wholesale-b2b-pro.php'], 'yayboost-sales-booster-for-woocommerce' => ['yayboost-pro/yayboost-sales-booster-for-woocommerce.php'], 'cf7-multi-step' => ['contact-form-7-multi-step-pro/contact-form-7-multi-step.php'], 'cf7-database' => ['contact-form-7-database-pro/cf7-database.php'], 'wp-whatsapp' => ['whatsapp-for-wordpress/whatsapp.php']];
        foreach ($map[$slug] ?? [] as $basename) {
            if (array_key_exists($basename, $all_plugins)) {
                return $basename;
            }
        }
        return null;
    }
}
