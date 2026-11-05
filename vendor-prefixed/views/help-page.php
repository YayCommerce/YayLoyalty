<?php

namespace YayLoyaltyScoped;

/**
 * Help page view — minimal with support links.
 *
 * @package YayCommerce\AdminShell\Views
 */
\defined('ABSPATH') || exit;
?>
<div class="wrap">
    <h1><?php 
\esc_html_e('YayCommerce Help', 'yaycommerce');
?></h1>
    <p><?php 
\esc_html_e('Need help? Visit our support resources:', 'yaycommerce');
?></p>
    <ul>
        <li><a href="https://yaycommerce.com/support" target="_blank" rel="noopener noreferrer"><?php 
\esc_html_e('Support Center', 'yaycommerce');
?></a></li>
        <li><a href="https://yaycommerce.com/documentation" target="_blank" rel="noopener noreferrer"><?php 
\esc_html_e('Documentation', 'yaycommerce');
?></a></li>
        <li><a href="https://yaycommerce.com/contact" target="_blank" rel="noopener noreferrer"><?php 
\esc_html_e('Contact Us', 'yaycommerce');
?></a></li>
    </ul>
</div>
<?php 
