<?php
declare(strict_types=1);
use Isolated\Symfony\Component\Finder\Finder;

return [
    'prefix'  => 'YayLoyaltyScoped',
    'finders' => [
        Finder::create()->files()->ignoreVCS(true)->ignoreDotFiles(true)->name('*.php')
            ->in('vendor/yaycommerce/admin-shell/src'),
        Finder::create()->files()->ignoreVCS(true)->ignoreDotFiles(true)->name('*.php')
            ->in('vendor/yaycommerce/admin-shell/views'),
    ],
    'exclude-namespaces' => [],
    'exclude-classes'    => [
        'WP_Error', 'WP_REST_Request', 'WP_REST_Response', 'WP_REST_Server', 'WP_Ajax_Upgrader_Skin', 'Plugin_Upgrader',
        'EDD_SL_Plugin_Updater', 'YayLoyaltyPluginAdapter',
    ],
    'exclude-functions'  => [
        'wp_.*', 'get_.*', 'add_.*', 'remove_.*', 'apply_filters', 'do_action',
        'plugin_basename', 'plugin_dir_path', 'plugin_dir_url', 'admin_url', 'home_url',
        'is_admin', 'current_user_can', 'sanitize_text_field', 'sanitize_key',
        'wp_json_encode', 'wp_unslash', 'esc_html', 'esc_attr', 'esc_url',
        'esc_url_raw', 'esc_html__', 'esc_attr__', 'esc_attr_e', 'esc_html_e',
        '__', '_e', '_n', '_x', 'add_query_arg', 'remove_query_arg', 'self_admin_url', 'admin_url','remove_all_actions', 'method_exists',
    ],
    'exclude-constants'  => [
        'ABSPATH', 'WPINC', 'WP_CONTENT_DIR', 'WP_DEBUG',
        'DOING_AJAX', 'DOING_CRON',
        '/^WP_.*/', '/^YAYCOMMERCE_.*/',
    ],
];