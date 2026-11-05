<?php

namespace YayLoyaltyScoped;

/**
 * "WooCommerce required" full-screen gate.
 *
 * Rendered in place of a plugin's settings page when the plugin depends on
 * WooCommerce and WooCommerce is inactive (see PluginSubmenu + the adapter's
 * optional needs_woocommerce_screen() method). Without this, WooCommerce-only
 * settings apps mount into an empty container and spin forever.
 *
 * Expects $copy (array) supplied by WooCommerceRequiredPage::render():
 *   icon, title, text, button, hint.
 *
 * @package YayCommerce\AdminShell\Views
 * @var array $copy
 */
\defined('ABSPATH') || exit;
$yaycommerce_install_wc_url = \esc_url(\admin_url('plugin-install.php?s=woocommerce&tab=search&type=term'));
?>
<style>
	#wpcontent #wpbody .notice,
	.error, .updated { display: none; }
	#wpfooter { display: none; }
	.yaycommerce-requirement-screen {
		display: flex;
		align-items: center;
		justify-content: center;
		min-height: calc(100vh - 160px);
		padding: 24px;
		box-sizing: border-box;
	}
	.yaycommerce-requirement-card {
		max-width: 460px;
		width: 100%;
		text-align: center;
		background: #ffffff;
		border: 1px solid #e4e4e7;
		border-radius: 12px;
		padding: 40px 32px;
		box-shadow: 0 1px 3px rgba( 0, 0, 0, 0.06 );
	}
	.yaycommerce-requirement-card__icon { font-size: 44px; line-height: 1; margin-bottom: 16px; }
	.yaycommerce-requirement-card__title { font-size: 22px; font-weight: 600; color: #1e1e1e; margin: 0 0 12px; }
	.yaycommerce-requirement-card__text { font-size: 14px; line-height: 1.6; color: #50575e; margin: 0 0 24px; }
	.yaycommerce-requirement-card__button {
		display: inline-block;
		background: #873eff;
		color: #ffffff;
		font-size: 14px;
		font-weight: 600;
		text-decoration: none;
		padding: 10px 24px;
		border-radius: 6px;
		transition: background 0.15s ease;
	}
	.yaycommerce-requirement-card__button:hover,
	.yaycommerce-requirement-card__button:focus { background: #6f2fe0; color: #ffffff; }
	.yaycommerce-requirement-card__hint { font-size: 12px; color: #787c82; margin: 20px 0 0; }
</style>
<div class="yaycommerce-requirement-screen">
	<div class="yaycommerce-requirement-card">
		<div class="yaycommerce-requirement-card__icon" aria-hidden="true"><?php 
echo \esc_html($copy['icon']);
?></div>
		<h1 class="yaycommerce-requirement-card__title"><?php 
echo \esc_html($copy['title']);
?></h1>
		<p class="yaycommerce-requirement-card__text"><?php 
echo \esc_html($copy['text']);
?></p>
		<a class="yaycommerce-requirement-card__button" href="<?php 
echo \esc_url($yaycommerce_install_wc_url);
?>">
			<?php 
echo \esc_html($copy['button']);
?>
		</a>
		<p class="yaycommerce-requirement-card__hint"><?php 
echo \esc_html($copy['hint']);
?></p>
	</div>
</div>
<?php 
