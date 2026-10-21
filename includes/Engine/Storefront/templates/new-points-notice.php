<?php
/**
 * The launcher panel's "You earned 40 new points.", under the points card while points were issued since
 * the shopper last opened the panel (NewPointsNotice). Styled by assets/storefront/account/new-points-notice.css
 * on top of points-card.css's notice.
 *
 * @var array $yayloyalty_member LauncherPayload's `member`; `new_points` set by Launcher::view() for a live member.
 */

use YayLoyalty\Engine\Storefront\AccountRewardsIcon;
use YayLoyalty\Engine\Storefront\LauncherCopy;
use YayLoyalty\Helpers\SettingsHelper;

defined( 'ABSPATH' ) || exit;

$yayloyalty_new_points = (int) ( $yayloyalty_member['new_points'] ?? 0 );

if ( $yayloyalty_new_points <= 0 ) {
    return;
}
?>
<p class="yayloyalty-account__notice yayloyalty-account__notice--new">
    <?php echo AccountRewardsIcon::get( 'megaphone' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed SVG. ?>
    <span class="yayloyalty-account__notice-text">
        <?php
        /* translators: 1: points number, as "40", 2: the store's points label agreeing with it, as "Points". */
        echo esc_html( sprintf( __( 'You earned %1$s new %2$s.', 'yayloyalty' ), LauncherCopy::points( $yayloyalty_new_points ), SettingsHelper::points_label( $yayloyalty_new_points ) ) );
        ?>
    </span>
</p>
