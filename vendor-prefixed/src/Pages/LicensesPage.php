<?php

namespace YayLoyaltyScoped\YayCommerce\AdminShell\Pages;

use YayLoyaltyScoped\YayCommerce\AdminShell\Support\AdminContext;
/**
 * Renders the Licenses admin page.
 * Ported from YayMail Pro LicensesMenu.php.
 */
class LicensesPage
{
    public static function render(): void
    {
        // Exposed to the included view so it can show a per-site notice in
        // Network Admin (license keys are stored/activated per site).
        $is_network = AdminContext::is_network();
        ?>
        <script>
            document.querySelector("#wpbody-content").innerHTML = "";
        </script>
        <?php 
        include __DIR__ . '/../../views/licenses-page.php';
    }
    public static function load_data(): void
    {
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_scripts']);
    }
    public static function enqueue_scripts(): void
    {
        $assets_url = plugin_dir_url(__FILE__) . '../../assets/';
        wp_enqueue_style('yaycommerce-licenses', $assets_url . 'css/licenses.css', [], '1.0');
    }
}
