<?php

namespace YayLoyaltyScoped;

/**
 * Other plugins content partial — rendered in AJAX response.
 * Expects $recommended_plugins in scope.
 * Ported from YayMail Pro other-plugins-content.php.
 *
 * @package YayCommerce\AdminShell\Views
 */
use YayLoyaltyScoped\YayCommerce\AdminShell\Pages\RecommendedPluginsPage;
\defined('ABSPATH') || exit;
if (empty($recommended_plugins)) {
    echo '<p>' . \esc_html__('No plugins found.', 'yaycommerce') . '</p>';
    return;
}
$page_instance = RecommendedPluginsPage::get_instance();
foreach ($recommended_plugins as $key => $plugin_detail) {
    $plugin_slug = $plugin_detail['slug'];
    $all_plugins = get_plugins();
    $install_status = install_plugin_install_status((object) ['slug' => $plugin_slug, 'version' => $plugin_detail['version']]);
    $status = $install_status['status'] ?? 'not_installed';
    $exist_pro_ver = $page_instance->check_pro_version_exists($plugin_detail);
    $is_installed = \in_array($status, ['latest_installed', 'newer_installed'], \true) || $exist_pro_ver;
    $is_active = 'active' === $status || $exist_pro_ver && is_plugin_active($exist_pro_ver) || $is_installed && is_plugin_active($install_status['file']);
    $is_update = 'update_available' === $status;
    // Hide CF7 add-ons when Contact Form 7 itself is not active
    $cf7_dependent_slugs = ['cf7-multi-step', 'cf7-database'];
    if (\in_array($plugin_slug, $cf7_dependent_slugs, \true) && !is_plugin_active('contact-form-7/wp-contact-form-7.php')) {
        continue;
    }
    // Hide active plugins that don't need updates
    if ($is_active && !$is_update) {
        continue;
    }
    $plugin_info_url = \add_query_arg(['tab' => 'plugin-information', 'plugin' => $plugin_slug, 'TB_iframe' => 'true', 'width' => 600, 'height' => 550], \self_admin_url('plugin-install.php'));
    ?>
    <div class="plugin-card plugin-card-<?php 
    echo \esc_attr($plugin_slug);
    ?>">
        <div class="plugin-card-top">
            <div class="name column-name">
                <h3>
                    <a href="<?php 
    echo \esc_url($plugin_info_url);
    ?>" class="thickbox open-plugin-details-modal">
                        <?php 
    echo \esc_html($plugin_detail['name']);
    ?>
                        <img src="<?php 
    echo \esc_url($plugin_detail['icon'] ?? '');
    ?>" class="plugin-icon" alt="">
                    </a>
                </h3>
            </div>
            <div class="desc column-description">
                <p><?php 
    echo \esc_html($plugin_detail['short_description'] ?? '');
    ?></p>
            </div>
        </div>
        <div class="plugin-card-bottom">
            <div class="column-rating">
                <?php 
    if ($is_active || $exist_pro_ver && is_plugin_active($exist_pro_ver)) {
        ?>
                    <?php 
        \esc_html_e('Status:', 'yaycommerce');
        ?>
                    <span class="plugin-status-active" data-plugin-file="<?php 
        echo \esc_attr($exist_pro_ver ?? $install_status['file'] ?? '');
        ?>"><?php 
        \esc_html_e('Active', 'yaycommerce');
        ?></span>
                <?php 
    } elseif ($is_installed) {
        ?>
                    <?php 
        \esc_html_e('Status:', 'yaycommerce');
        ?>
                    <span class="plugin-status-inactive" data-plugin-file="<?php 
        echo \esc_attr($exist_pro_ver ?? $install_status['file'] ?? '');
        ?>"><?php 
        \esc_html_e('Inactive', 'yaycommerce');
        ?></span>
                <?php 
    } elseif ($is_update) {
        ?>
                    <?php 
        \esc_html_e('Status:', 'yaycommerce');
        ?>
                    <span class="plugin-status-update-available"><?php 
        \esc_html_e('Update available', 'yaycommerce');
        ?></span>
                <?php 
    } else {
        ?>
                    <?php 
        \esc_html_e('Status:', 'yaycommerce');
        ?>
                    <span class="plugin-status-not-install" data-plugin-url="<?php 
        echo \esc_url($plugin_detail['download_link'] ?? '');
        ?>"><?php 
        \esc_html_e('Not installed', 'yaycommerce');
        ?></span>
                <?php 
    }
    ?>
            </div>
            <div class="column-updated">
                <ul class="plugin-action-buttons">
                    <?php 
    if ($is_active || $exist_pro_ver && is_plugin_active($exist_pro_ver)) {
        ?>
                        <li><button class="button button-disabled" disabled><?php 
        \esc_html_e('Activated', 'yaycommerce');
        ?></button></li>
                    <?php 
    } elseif ($is_installed) {
        ?>
                        <li><button class="button activate-now" data-plugin-file="<?php 
        echo \esc_attr($exist_pro_ver ?? $install_status['file'] ?? '');
        ?>"><?php 
        \esc_html_e('Activate', 'yaycommerce');
        ?></button></li>
                    <?php 
    } elseif ($is_update) {
        ?>
                        <li><button class="button update-now" data-plugin="<?php 
        echo \esc_attr($exist_pro_ver ?? $install_status['file'] ?? '');
        ?>"><?php 
        \esc_html_e('Update Now', 'yaycommerce');
        ?></button></li>
                    <?php 
    } else {
        ?>
                        <li><button class="button button-primary install-now" data-install-url="<?php 
        echo \esc_url($plugin_detail['download_link'] ?? '');
        ?>"><?php 
        \esc_html_e('Install Now', 'yaycommerce');
        ?></button></li>
                    <?php 
    }
    ?>
                </ul>
            </div>
        </div>
    </div>
<?php 
}
