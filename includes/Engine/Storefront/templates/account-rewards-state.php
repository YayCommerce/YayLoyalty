<?php
/**
 * My Account → Rewards when it has nothing to show: the program is paused, this account is outside it, or the tab
 * failed to render. The card is the tab's whole body, without the intro line.
 * Styled by assets/storefront/account/account-rewards-state.css.
 *
 * @var string $title The card's heading.
 * @var string $text  The line under it.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="yayloyalty-account">
    <section class="yayloyalty-account__state">
        <h2 class="yayloyalty-account__state-title"><?php echo esc_html( $title ); ?></h2>
        <p class="yayloyalty-account__state-text"><?php echo esc_html( $text ); ?></p>
    </section>
</div>
