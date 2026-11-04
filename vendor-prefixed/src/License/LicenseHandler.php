<?php

namespace YayLoyaltyScoped\YayCommerce\AdminShell\License;

use YayLoyaltyScoped\YayCommerce\AdminShell\License\Contracts\LicenseConfigAdapter;
use YayLoyaltyScoped\YayCommerce\AdminShell\Support\Slug;
defined('ABSPATH') || exit;
/**
 * License activation/deactivation/check state machine.
 *
 * NOTE: This file exceeds 200 LOC because it aggregates all WordPress admin
 * hook wiring (notices, cron, auto-update, plugin-row notifications) into one
 * class — splitting would require injecting WP globals and create more
 * indirection without clarity gain. Acceptable exception per code standards.
 *
 *
 * Ported from YayMail Pro LicenseHandler.php — all YAYMAIL_* constants and
 * CorePlugin::get() calls replaced with $this->adapter->get_*() methods.
 * The hardcoded yaycommerce_licensing_plugins filter registration at priority 100
 * is preserved for backwards compat — new code uses LicenseRegistry instead.
 *
 * NOTE: The original LicenseHandler used CorePlugin::get() for item_id/slug/etc.
 * Here those values come from the injected LicenseConfigAdapter. Port is faithful;
 * any oddities from source preserved with // TODO: investigate comments.
 */
class LicenseHandler
{
    protected LicenseConfigAdapter $adapter;
    protected License $license;
    public function __construct(LicenseConfigAdapter $adapter)
    {
        $this->adapter = $adapter;
        $this->license = new License($adapter);
        new RestAPI($adapter);
        // The daily check runs from wp-cron.php, which is not an admin request,
        // so its callback must be attached on every request.
        $this->register_cron_callback();
        if (is_admin()) {
            $this->do_hooks();
            $this->do_cron_job();
            $this->do_post_requests();
            $this->show_plugin_page_notification();
        }
    }
    public function do_hooks(): void
    {
        add_action('admin_notices', [$this, 'not_activate_license_notice']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_license_scripts']);
        add_action('yaycommerce_licenses_page', [$this, 'render_license_settings'], 100);
        // Legacy filter — new code uses LicenseRegistry, but this keeps
        // backward compat so old Licenses page views still work during transition.
        add_filter('yaycommerce_licensing_plugins', [$this, 'register_licensing_plugins'], 100);
        /** Expired license admin notice */
        add_action('admin_notices', [$this, 'license_expired_admin_notice']);
        // License-specific action link: "Enter license key" when inactive
        if (!$this->license->is_active() || $this->license->is_expired()) {
            add_filter('plugin_action_links_' . $this->adapter->get_plugin_basename(), [$this, 'add_license_action_link']);
        }
        add_action('admin_init', [$this, 'auto_update']);
        add_filter('auto_update_plugin', [$this, 'add_disabled_auto_update_text'], 100, 2);
        add_filter('plugins_list', [$this, 'support_auto_update'], 100);
    }
    /**
     * Handle POST-based license form submissions (legacy AJAX path).
     * REST API (RestAPI.php) is the primary path — this is a no-op
     * placeholder for backward compatibility with any direct form posts.
     */
    public function do_post_requests(): void
    {
        // REST API handles all license actions. No legacy POST handling needed.
    }
    public function register_cron_callback(): void
    {
        add_filter('cron_schedules', [$this, 'custom_schedules']);
        add_action('check_license_cron_' . $this->adapter->get_plugin_slug(), [$this, 'check_license_cron_run']);
    }
    public function do_cron_job(): void
    {
        $cron_hook = 'check_license_cron_' . $this->adapter->get_plugin_slug();
        if (!wp_next_scheduled($cron_hook)) {
            wp_schedule_event(time(), 'daily', $cron_hook);
        }
    }
    public function custom_schedules(array $schedules): array
    {
        $schedules['3hours'] = ['interval' => 60 * 60 * 3, 'display' => 'Three Hours'];
        return $schedules;
    }
    public function check_license_cron_run(): void
    {
        $this->license->update();
    }
    public function enqueue_license_scripts(): void
    {
        if (!isset($_GET['page']) || 'yaycommerce-licenses' !== $_GET['page']) {
            // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }
        $slug = $this->adapter->get_plugin_slug();
        $assets_url = plugin_dir_url(__FILE__) . '../../assets/';
        wp_enqueue_script('yaycommerce-license', $assets_url . 'js/license.js', ['jquery'], $this->adapter->get_plugin_version(), \true);
        wp_localize_script('yaycommerce-license', Slug::to_var_name($slug) . 'LicenseData', ['slug' => $slug, 'apiSettings' => ['restNonce' => wp_create_nonce('wp_rest'), 'restUrl' => esc_url_raw(rest_url(Slug::to_var_name($slug) . '/v1')), 'adminUrl' => admin_url()]]);
    }
    public function render_license_settings(): void
    {
        $license = $this->license;
        $_plugin = ['slug' => $this->adapter->get_plugin_slug(), 'name' => $this->adapter->get_plugin_name()];
        if ($license->is_active()) {
            include __DIR__ . '/../../views/license-card.php';
        } else {
            include __DIR__ . '/../../views/license-activate-card.php';
        }
    }
    public function register_licensing_plugins(array $plugins = []): array
    {
        $plugin_data = ['name' => $this->adapter->get_plugin_name(), 'slug' => $this->adapter->get_plugin_slug(), 'basename' => $this->adapter->get_plugin_basename(), 'file' => $this->adapter->get_plugin_file(), 'url' => $this->adapter->get_store_link(), 'item_id' => $this->adapter->get_item_id()];
        return array_merge($plugins, [$plugin_data]);
    }
    public function not_activate_license_notice(): void
    {
        $current_screen = get_current_screen();
        if (!$current_screen || $current_screen->id !== 'plugins') {
            return;
        }
        if ($this->license->is_active()) {
            return;
        }
        $slug = $this->adapter->get_plugin_slug();
        $name = $this->adapter->get_plugin_name();
        ?>
        <div class="error">
            <p>
            <?php 
        // translators: %1$s plugin name, %2$s link open, %3$s link close
        printf(esc_html__('%1$s license key is required. %2$sPlease enter your license key to start using the plugin%3$s.', 'yaycommerce'), esc_html($name), '<a href="' . esc_url(admin_url('admin.php?page=yaycommerce-licenses')) . '">', '</a>');
        ?>
            </p>
        </div>
        <?php 
    }
    public function license_expired_admin_notice(): void
    {
        if ($this->license->is_active() && $this->license->is_expired()) {
            $name = $this->adapter->get_plugin_name();
            $renewal_url = $this->license->get_renewal_url();
            ?>
            <div class="notice notice-warning">
                <p>
                <?php 
            printf(
                /* translators: %1$s plugin name, %2$s renewal URL */
                esc_html__('%1$s: Your license has expired. %2$sRenew now%3$s to continue receiving updates.', 'yaycommerce'),
                esc_html($name),
                '<a href="' . esc_url($renewal_url) . '" target="_blank">',
                '</a>'
            );
            ?>
                </p>
            </div>
            <?php 
        }
    }
    public function show_plugin_page_notification(): void
    {
        add_action('after_plugin_row_' . $this->adapter->get_plugin_basename(), [$this, 'plugin_notifications'], 10, 2);
    }
    public function plugin_notifications(string $file): void
    {
        if ($this->adapter->get_plugin_basename() !== $file) {
            return;
        }
        if (!$this->license->is_active() || $this->license->is_expired()) {
            ?>
            <tr class="plugin-update-tr active"><td colspan="4" class="plugin-update colspanchange" style="box-shadow:none;">
            <?php 
            if (!$this->license->is_active()) {
                ?>
                <div class="update-message notice inline notice-warning notice-alt">
                    <p>
                        <a href="<?php 
                echo esc_url(admin_url('admin.php?page=yaycommerce-licenses'));
                ?>">
                            <?php 
                esc_html_e('Please activate your license for access to premium features and automatic updates', 'yaycommerce');
                ?>
                        </a>.
                    </p>
                </div>
            <?php 
            }
            ?>
            <?php 
            if ($this->license->is_expired()) {
                ?>
                <div class="update-message notice inline notice-warning notice-alt">
                    <p class="license_expired_text">
                        <span><?php 
                echo esc_html('Your license has expired, please ');
                ?></span>
                        <a target="_blank" href="<?php 
                echo esc_url($this->license->get_renewal_url());
                ?>"><?php 
                echo esc_html('renew this license');
                ?></a>
                        <span><?php 
                echo esc_html(' to download this update. ');
                ?></span>
                    </p>
                </div>
            <?php 
            }
            ?>
            </td></tr>
            <?php 
        }
    }
    /**
     * Add "Enter license key" action link when license is inactive.
     */
    public function add_license_action_link(array $action_links): array
    {
        $link = ['license' => '<a href="' . admin_url('admin.php?page=yaycommerce-licenses') . '">' . esc_html__('Enter license key', 'yaycommerce') . '</a>'];
        return array_merge($link, $action_links);
    }
    public function auto_update(): void
    {
        $doing_cron = defined('DOING_CRON') && \DOING_CRON;
        if (!current_user_can($this->adapter->get_capability()) && !$doing_cron) {
            return;
        }
        $license_key = '';
        if ($this->license->is_active() && !$this->license->is_expired()) {
            $license_key = (string) $this->license->get_license_key();
        }
        $args = ['version' => $this->adapter->get_plugin_version(), 'license' => $license_key, 'author' => 'YayCommerce', 'item_id' => $this->adapter->get_item_id()];
        new EDD_SL_Plugin_Updater($this->adapter->get_store_url(), $this->adapter->get_plugin_file(), $args);
    }
    public static function remove_site_plugin_check(LicenseConfigAdapter $adapter): void
    {
        global $pagenow;
        if ('plugin-install.php' === $pagenow) {
            return;
        }
        if (!function_exists('YayLoyaltyScoped\get_plugin_data')) {
            require_once \ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $basename = $adapter->get_plugin_basename();
        $site_transient_update_plugins = get_site_transient('update_plugins');
        if (isset($site_transient_update_plugins->checked[$basename])) {
            unset($site_transient_update_plugins->checked[$basename]);
            set_site_transient('update_plugins', $site_transient_update_plugins);
        }
    }
    public function support_auto_update(array $plugins): array
    {
        foreach ($plugins['all'] as $ind => $active_plugin) {
            if ('YayCommerce' === $active_plugin['Author']) {
                $plugins['all'][$ind]['update-supported'] = \true;
            }
        }
        return $plugins;
    }
    public function add_disabled_auto_update_text($value, $plugin_info)
    {
        if (!isset($plugin_info->plugin)) {
            return $value;
        }
        if ($plugin_info->plugin === $this->adapter->get_plugin_basename()) {
            if (!$this->license->is_active() || $this->license->is_expired()) {
                return \false;
            }
        }
        return $value;
    }
    public function is_license_inactive(): bool
    {
        return !$this->license->is_active();
    }
    public function get_license(): License
    {
        return $this->license;
    }
}
