<?php

namespace YayLoyaltyScoped;

/**
 * License "active" card — shows current license state with update/deactivate buttons.
 *
 * De-branded port from YayMail Pro information-card.php.
 * Expects in scope: $license (License), $_plugin ['slug', 'name'].
 *
 * @package YayCommerce\AdminShell\Views
 */
\defined('ABSPATH') || exit;
$plugin_slug = $_plugin['slug'];
$plugin_name = $_plugin['name'];
$license_info = $license->get_license_info();
$expires = $license_info['expires'] ?? 'Not updated';
$is_expired = $license->is_expired();
$is_addon = \false !== \strpos($plugin_slug, 'addon');
?>
<div class="yaycommerce-license-card" id="<?php 
echo \esc_attr("{$plugin_slug}_license_card");
?>" style="<?php 
echo \esc_attr($is_addon ? 'order: 999;' : '');
?>">
    <div class="yaycommerce-license-card-header">
        <div class="yaycommerce-license-card-title-wrapper">
            <h3 class="yaycommerce-license-card-title yaycommerce-license-card-header-item">
                <?php 
echo \esc_html($plugin_name);
?>
                <span class="yaycommerce-license-badge <?php 
echo \esc_attr($is_expired ? 'error' : 'success');
?>">
                    <?php 
echo \esc_html($is_expired ? \__('Expired', 'yaycommerce') : \__('Active', 'yaycommerce'));
?>
                </span>
            </h3>
        </div>
    </div>
    <div class="yaycommerce-license-card-body">
        <label for="<?php 
echo \esc_attr("{$plugin_slug}_license_input");
?>"><?php 
\esc_html_e('Your license key:', 'yaycommerce');
?></label>
        <div class="yaycommerce-license-input-row">
            <input type="text" id="<?php 
echo \esc_attr("{$plugin_slug}_license_input");
?>" disabled value="<?php 
echo \esc_attr($license->format_license_key());
?>">
            <button class="yaycommerce-license-button yaycommerce-update-license" data-plugin="<?php 
echo \esc_attr($plugin_slug);
?>">
                <span><?php 
\esc_html_e('Update', 'yaycommerce');
?></span>
                <span class="activate-loading sync-loading" style="display:none;"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M21.66 10.37a.62.62 0 00.07-.19l.75-4a1 1 0 00-2-.36l-.37 2a9.22 9.22 0 00-16.58.84 1 1 0 00.55 1.3 1 1 0 001.31-.55A7.08 7.08 0 0112.07 5a7.17 7.17 0 016.24 3.58l-1.65-.27a1 1 0 10-.32 2l4.25.71h.16a.93.93 0 00.34-.06.33.33 0 00.1-.06.78.78 0 00.2-.11l.08-.1a1.07 1.07 0 00.14-.16.58.58 0 00.05-.16zM19.88 14.07a1 1 0 00-1.31.56A7.08 7.08 0 0111.93 19a7.17 7.17 0 01-6.24-3.58l1.65.27h.16a1 1 0 00.16-2L3.41 13a.91.91 0 00-.33 0H3a1.15 1.15 0 00-.32.14 1 1 0 00-.18.18l-.09.1a.84.84 0 00-.07.19.44.44 0 00-.07.17l-.75 4a1 1 0 00.8 1.22h.18a1 1 0 001-.82l.37-2a9.22 9.22 0 0016.58-.83 1 1 0 00-.57-1.28z"></path></svg></span>
            </button>
            <button class="yaycommerce-license-button yaycommerce-remove-license" data-plugin="<?php 
echo \esc_attr($plugin_slug);
?>">
                <span><?php 
\esc_html_e('Deactivate', 'yaycommerce');
?></span>
                <span class="activate-loading sync-loading" style="display:none;"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M21.66 10.37a.62.62 0 00.07-.19l.75-4a1 1 0 00-2-.36l-.37 2a9.22 9.22 0 00-16.58.84 1 1 0 00.55 1.3 1 1 0 001.31-.55A7.08 7.08 0 0112.07 5a7.17 7.17 0 016.24 3.58l-1.65-.27a1 1 0 10-.32 2l4.25.71h.16a.93.93 0 00.34-.06.33.33 0 00.1-.06.78.78 0 00.2-.11l.08-.1a1.07 1.07 0 00.14-.16.58.58 0 00.05-.16zM19.88 14.07a1 1 0 00-1.31.56A7.08 7.08 0 0111.93 19a7.17 7.17 0 01-6.24-3.58l1.65.27h.16a1 1 0 00.16-2L3.41 13a.91.91 0 00-.33 0H3a1.15 1.15 0 00-.32.14 1 1 0 00-.18.18l-.09.1a.84.84 0 00-.07.19.44.44 0 00-.07.17l-.75 4a1 1 0 00.8 1.22h.18a1 1 0 001-.82l.37-2a9.22 9.22 0 0016.58-.83 1 1 0 00-.57-1.28z"></path></svg></span>
            </button>
        </div>
        <div class="<?php 
echo \esc_attr($plugin_slug);
?>-license-message yaycommerce-license-message"></div>
        <div><?php 
\esc_html_e('Expiration Date:', 'yaycommerce');
?>
            <?php 
if ('lifetime' === $expires) {
    echo '<strong>' . \esc_html__('Lifetime', 'yaycommerce') . '</strong>';
} elseif ('Not updated' === $expires) {
    echo '<strong>' . \esc_html__('Not updated', 'yaycommerce') . '</strong>';
} else {
    $time_in_tz = \strtotime($expires);
    echo '<strong>' . \esc_html(\gmdate('F j, Y', $time_in_tz)) . '</strong>';
}
if ($is_expired) {
    echo '<strong class="yaycommerce-license-expired-text"> (' . \esc_html__('Expired', 'yaycommerce') . ')</strong>';
}
?>
        </div>
    </div>
    <div class="yaycommerce-license-card-footer">
        <?php 
if ($is_expired) {
    ?>
            <div>
                <strong class="yaycommerce-license-expired-text">
                    <?php 
    \esc_html_e('Your license is expired!', 'yaycommerce');
    ?>
                    <a href="<?php 
    echo \esc_url($license->get_renewal_url());
    ?>" class="yaycommerce-license-expired-text" target="_blank">
                        <?php 
    \esc_html_e('Renew Now!', 'yaycommerce');
    ?>
                    </a>
                </strong>
            </div>
        <?php 
}
?>
        <?php 
if (isset($license_info['license_limit']) && 0 !== (int) $license_info['license_limit']) {
    ?>
            <div>
                <?php 
    \esc_html_e('Need more licenses?', 'yaycommerce');
    ?>
                <a class="yaycommerce-license-buy-now" href="<?php 
    echo \esc_url($license->get_upgrade_url());
    ?>" target="_blank">
                    <?php 
    \esc_html_e('Upgrade to unlimited', 'yaycommerce');
    ?>
                </a>
            </div>
        <?php 
}
?>
    </div>
</div>
<?php 
