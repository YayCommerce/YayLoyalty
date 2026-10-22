<?php
/**
 * The points card: the Rewards tab's balance block, also the launcher panel's and placed by [yayloyalty_balance] and its block.
 * A guest gets a sign-in link where the number would be. A member whose points expire within
 * LauncherPayload::EXPIRING_SOON_DAYS (the member's `expires_at`, set only for a balance above 0) gets a notice under it.
 * Styled by assets/storefront/account/points-card.css.
 *
 * @var array $yayloyalty_card AccountRewards::card_view().
 */

use YayLoyalty\Engine\Storefront\AccountRewardsIcon;
use YayLoyalty\Engine\Storefront\LauncherCopy;
use YayLoyalty\Engine\Storefront\LauncherEarnRules;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

$yayloyalty_card_payload = $yayloyalty_card['payload'];
$yayloyalty_card_member  = $yayloyalty_card_payload['member'];
$yayloyalty_card_redeem  = $yayloyalty_card_payload['redeem'];
$yayloyalty_card_label   = SettingsHelper::points_label_plural();
$yayloyalty_card_caption = '';

if ( null !== $yayloyalty_card_member && null !== $yayloyalty_card_member['worth'] ) {
    /* translators: %s: money amount. */
    $yayloyalty_card_caption = sprintf( __( 'Worth %s off your next order', 'yayloyalty' ), LauncherCopy::money( $yayloyalty_card_member['worth'] ) );
} elseif ( null !== $yayloyalty_card_member && null !== $yayloyalty_card_redeem ) {
    $yayloyalty_card_threshold = $yayloyalty_card_redeem['unlock_points'];
    $yayloyalty_card_need      = $yayloyalty_card_threshold - $yayloyalty_card_member['balance'];
    $yayloyalty_card_unlock    = LauncherCopy::money( (float) $yayloyalty_card_redeem['unlock_off'] );

    if ( $yayloyalty_card_need > 0 && null !== $yayloyalty_card_redeem['unlock_off'] ) {
        $yayloyalty_card_caption = 0 === $yayloyalty_card_member['balance']
            /* translators: 1: points with the store's label, as "100 Points", 2: money amount. */
            ? sprintf( __( 'Earn your first %1$s to unlock %2$s off.', 'yayloyalty' ), SettingsHelper::points_text( $yayloyalty_card_threshold ), $yayloyalty_card_unlock )
            /* translators: 1: points with the store's label, as "40 Points", 2: money amount. */
            : sprintf( __( 'Earn %1$s more to unlock %2$s off.', 'yayloyalty' ), SettingsHelper::points_text( $yayloyalty_card_need ), $yayloyalty_card_unlock );
    }
}
?>
<section class="yayloyalty-account__card">
    <div class="yayloyalty-account__card-body">
        <h2 class="yayloyalty-account__heading"><?php echo esc_html( LauncherCopy::balance_heading() ); ?></h2>
        <?php if ( null === $yayloyalty_card_member ) : ?>
            <p class="yayloyalty-account__signin">
                <a href="<?php echo esc_url( $yayloyalty_card_payload['account_url'] ); ?>">
                    <?php
                    /* translators: %s: the store's points label, as "Points". */
                    echo esc_html( sprintf( __( 'Sign in to see your %s', 'yayloyalty' ), $yayloyalty_card_label ) );
                    ?>
                </a>
            </p>
        <?php else : ?>
            <p class="yayloyalty-account__amount">
                <span class="yayloyalty-account__balance"><?php echo esc_html( LauncherCopy::points( $yayloyalty_card_member['balance'] ) ); ?></span>
                <span class="yayloyalty-account__unit"><?php echo esc_html( SettingsHelper::points_label( $yayloyalty_card_member['balance'] ) ); ?></span>
            </p>
            <?php if ( '' !== $yayloyalty_card_caption ) : ?>
                <p class="yayloyalty-account__caption"><?php echo esc_html( $yayloyalty_card_caption ); ?></p>
            <?php endif; ?>
            <?php // Any balance can be taken to checkout, which says what it is worth there; an empty one is sent to earn, if there is a way to. ?>
            <?php if ( $yayloyalty_card_member['balance'] > 0 ) : ?>
                <a class="yayloyalty-account__button" href="<?php echo esc_url( $yayloyalty_card_payload['checkout_url'] ); ?>"><?php esc_html_e( 'Use points at checkout', 'yayloyalty' ); ?><?php echo AccountRewardsIcon::get( 'arrow' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed SVG. ?></a>
            <?php elseif ( [] !== LauncherEarnRules::member_rows( $yayloyalty_card_payload['earn'], $yayloyalty_card_payload['purchase_min'] ) ) : ?>
                <a class="yayloyalty-account__button" href="<?php echo esc_url( (string) wc_get_page_permalink( 'shop' ) ); ?>">
                    <?php
                    /* translators: %s: the store's points label, as "Points". */
                    echo esc_html( sprintf( __( 'Start earning %s', 'yayloyalty' ), $yayloyalty_card_label ) );
                    echo AccountRewardsIcon::get( 'arrow' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed SVG.
                    ?>
                </a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php require __DIR__ . '/card-art.php'; ?>
</section>
<?php if ( null !== $yayloyalty_card_member && null !== $yayloyalty_card_member['expires_at'] ) : ?>
    <p class="yayloyalty-account__notice">
        <?php echo AccountRewardsIcon::design( 'expiring' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed SVG. ?>
        <span class="yayloyalty-account__notice-text">
            <strong>
                <?php
                /* translators: 1: points with the store's label, as "200 Points", 2: date. */
                echo esc_html( sprintf( __( '%1$s expire on %2$s.', 'yayloyalty' ), SettingsHelper::points_text( $yayloyalty_card_member['balance'] ), Dates::to_site_date( $yayloyalty_card_member['expires_at'] ) ) );
                ?>
            </strong>
        </span>
        <?php // The notice shows only for a balance above 0, so the link always follows the card's checkout button. ?>
        <a class="yayloyalty-account__notice-link" href="<?php echo esc_url( $yayloyalty_card_payload['checkout_url'] ); ?>"><?php esc_html_e( 'Use points', 'yayloyalty' ); ?></a>
    </p>
<?php endif; ?>
