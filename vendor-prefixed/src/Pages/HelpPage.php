<?php

namespace YayLoyaltyScoped\YayCommerce\AdminShell\Pages;

/**
 * Help page — redirects to external support URL.
 */
class HelpPage
{
    public static function render(): void
    {
        // Fallback if redirect didn't fire (shouldn't happen).
        wp_safe_redirect('https://yaycommerce.com/support/');
        exit;
    }
    public static function load_data(): void
    {
        wp_redirect('https://yaycommerce.com/support/');
        exit;
    }
}
