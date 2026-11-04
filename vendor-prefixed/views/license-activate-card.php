<?php

namespace YayLoyaltyScoped;

/**
 * License "activate" card — shown when license is not active yet.
 * Expects in scope: $_plugin ['slug', 'name'].
 *
 * @package YayCommerce\AdminShell\Views
 */
\defined('ABSPATH') || exit;
$plugin_slug = $_plugin['slug'];
$plugin_name = $_plugin['name'];
?>
<div class="yaycommerce-license-card" id="<?php 
echo \esc_attr("{$plugin_slug}_license_card");
?>">
    <div class="yaycommerce-license-card-header">
        <h3 class="yaycommerce-license-card-title yaycommerce-license-card-header-item">
            <?php 
echo \esc_html($plugin_name);
?>
        </h3>
    </div>
    <div class="yaycommerce-license-card-body">
        <label for="<?php 
echo \esc_attr("{$plugin_slug}_license_input");
?>"><?php 
\esc_html_e('Your license key:', 'yaycommerce');
?></label>
        <div class="yaycommerce-license-input-row">
            <input type="password" autocomplete="new-password" id="<?php 
echo \esc_attr("{$plugin_slug}_license_input");
?>" placeholder="<?php 
\esc_attr_e('License key', 'yaycommerce');
?>">
            <button class="button button-primary yaycommerce-license-button yaycommerce-activate-license-button" data-plugin="<?php 
echo \esc_attr($plugin_slug);
?>">
                <span><?php 
\esc_html_e('Activate License', 'yaycommerce');
?></span>
                <span class="activate-loading sync-loading" style="display:none;"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M21.66 10.37a.62.62 0 00.07-.19l.75-4a1 1 0 00-2-.36l-.37 2a9.22 9.22 0 00-16.58.84 1 1 0 00.55 1.3 1 1 0 001.31-.55A7.08 7.08 0 0112.07 5a7.17 7.17 0 016.24 3.58l-1.65-.27a1 1 0 10-.32 2l4.25.71h.16a.93.93 0 00.34-.06.33.33 0 00.1-.06.78.78 0 00.2-.11l.08-.1a1.07 1.07 0 00.14-.16.58.58 0 00.05-.16zM19.88 14.07a1 1 0 00-1.31.56A7.08 7.08 0 0111.93 19a7.17 7.17 0 01-6.24-3.58l1.65.27h.16a1 1 0 00.16-2L3.41 13a.91.91 0 00-.33 0H3a1.15 1.15 0 00-.32.14 1 1 0 00-.18.18l-.09.1a.84.84 0 00-.07.19.44.44 0 00-.07.17l-.75 4a1 1 0 00.8 1.22h.18a1 1 0 001-.82l.37-2a9.22 9.22 0 0016.58-.83 1 1 0 00-.57-1.28z"></path></svg></span>
            </button>
        </div>
        <div class="<?php 
echo \esc_attr($plugin_slug);
?>-license-message yaycommerce-license-message"></div>
    </div>
</div>
<?php 
