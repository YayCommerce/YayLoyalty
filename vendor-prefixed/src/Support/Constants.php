<?php

namespace YayLoyaltyScoped\YayCommerce\AdminShell\Support;

defined('ABSPATH') || exit;
/**
 * Package-level constants with defined()-guards so individual plugins
 * that define their own copy (race condition) take precedence.
 */
if (!defined('YAYCOMMERCE_SELLER_SITE_URL')) {
    define('YAYCOMMERCE_SELLER_SITE_URL', 'https://yaycommerce.com/');
}
