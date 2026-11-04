<?php

namespace YayLoyaltyScoped\YayCommerce\AdminShell\Pages;

/**
 * Renders the shared "WooCommerce required" full-screen gate.
 *
 * Installed as the settings-page callback by PluginSubmenu when a plugin opts in
 * (adapter's optional needs_woocommerce_screen() returns true). Copy is
 * de-branded by default; a plugin may override any field via the adapter's
 * optional get_woocommerce_screen_copy() method.
 */
class WooCommerceRequiredPage
{
    /**
     * @param array $copy Optional overrides: icon, title, text, button, hint.
     */
    public static function render(array $copy = []): void
    {
        $copy = array_merge(self::defaults(), array_filter($copy));
        include __DIR__ . '/../../views/woocommerce-required-page.php';
    }
    /**
     * Generic, de-branded default copy.
     */
    private static function defaults(): array
    {
        return ['icon' => '🛒', 'title' => __('WooCommerce is required', 'yaycommerce'), 'text' => __('This plugin extends WooCommerce, so it needs WooCommerce installed and activated. Install WooCommerce to get started.', 'yaycommerce'), 'button' => __('Install WooCommerce', 'yaycommerce'), 'hint' => __('Already installed it? Activate WooCommerce, then reload this page.', 'yaycommerce')];
    }
}
