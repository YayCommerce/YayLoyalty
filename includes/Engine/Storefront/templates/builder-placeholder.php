<?php
/**
 * What a page builder shows in place of a placement that prints nothing for the person editing: its name and when it shows.
 * Never printed on the live page. Styled by assets/storefront/account/account-rewards-state.css, whose card it borrows.
 *
 * @var string $yayloyalty_placeholder_title The placement's name.
 * @var string $yayloyalty_placeholder_text  When it shows.
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="yayloyalty-account__state">
    <h2 class="yayloyalty-account__state-title"><?php echo esc_html( $yayloyalty_placeholder_title ); ?></h2>
    <p class="yayloyalty-account__state-text"><?php echo esc_html( $yayloyalty_placeholder_text ); ?></p>
</section>
