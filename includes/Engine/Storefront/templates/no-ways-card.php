<?php
/**
 * The card printed in place of "Ways to earn" and "Ways to redeem" when there is nothing to list: both on the
 * My Points tab and in the launcher panels, one of them in its own placement.
 * Styled by assets/storefront/account/account-rewards-state.css, whose card it borrows.
 *
 * @var string $yayloyalty_no_ways Which lists are empty: 'both', 'earn' or 'redeem'.
 */

use YayLoyalty\Helpers\SettingsHelper;

defined( 'ABSPATH' ) || exit;

$yayloyalty_no_ways_label = SettingsHelper::points_label_plural();

if ( 'earn' === $yayloyalty_no_ways ) {
    $yayloyalty_no_ways_title = __( 'No ways to earn yet', 'yayloyalty' );
    /* translators: %s: the store's points label, as "Points". */
    $yayloyalty_no_ways_text = sprintf( __( 'There are no ways to earn %s right now. Please check back later.', 'yayloyalty' ), $yayloyalty_no_ways_label );
} elseif ( 'redeem' === $yayloyalty_no_ways ) {
    $yayloyalty_no_ways_title = __( 'No ways to redeem yet', 'yayloyalty' );
    /* translators: %s: the store's points label, as "Points". */
    $yayloyalty_no_ways_text = sprintf( __( 'There are no ways to spend %s right now. Please check back later.', 'yayloyalty' ), $yayloyalty_no_ways_label );
} else {
    $yayloyalty_no_ways_title = __( 'No ways to earn or redeem yet', 'yayloyalty' );
    /* translators: %s: the store's points label, as "Points". */
    $yayloyalty_no_ways_text = sprintf( __( 'There are no ways to earn or spend %s right now. Please check back later.', 'yayloyalty' ), $yayloyalty_no_ways_label );
}
?>
<section class="yayloyalty-account__state">
    <h2 class="yayloyalty-account__state-title"><?php echo esc_html( $yayloyalty_no_ways_title ); ?></h2>
    <p class="yayloyalty-account__state-text"><?php echo esc_html( $yayloyalty_no_ways_text ); ?></p>
</section>
