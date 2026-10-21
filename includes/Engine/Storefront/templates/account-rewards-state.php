<?php
/**
 * My Account → Rewards when the shopper cannot take part: the program is paused, or this account is outside it.
 * Styled by assets/storefront/account/account-rewards-state.css.
 *
 * @var string $title The card's heading.
 * @var string $text  The line under it.
 */

use YayLoyalty\Engine\Storefront\AccountRewards;

defined( 'ABSPATH' ) || exit;
?>
<div class="yayloyalty-account">
    <p class="yayloyalty-account__intro"><?php echo esc_html( AccountRewards::intro() ); ?></p>
    <section class="yayloyalty-account__state">
        <h2 class="yayloyalty-account__state-title"><?php echo esc_html( $title ); ?></h2>
        <p class="yayloyalty-account__state-text"><?php echo esc_html( $text ); ?></p>
    </section>
</div>
