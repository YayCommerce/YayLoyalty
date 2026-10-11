<?php
/**
 * The points card: the Rewards tab's balance block, also placed by [yayloyalty_balance] and its block.
 * A guest gets a sign-in link where the number would be.
 *
 * @var array $yayloyalty_card AccountRewards::card_view().
 */

use YayLoyalty\Engine\Storefront\LauncherCopy;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

$yayloyalty_card_payload = $yayloyalty_card['payload'];
$yayloyalty_card_member  = $yayloyalty_card_payload['member'];
// 0 picks the plural label.
$yayloyalty_card_label = SettingsHelper::points_label( 0 );
?>
<section class="yayloyalty-account__card">
    <h2 class="yayloyalty-account__heading"><?php echo esc_html( $yayloyalty_card_payload['text']['balance_heading'] ); ?></h2>
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
        <p class="yayloyalty-account__balance"><?php echo esc_html( LauncherCopy::points( $yayloyalty_card_member['balance'] ) ); ?></p>
        <?php if ( null !== $yayloyalty_card_member['worth'] ) : ?>
            <p>
                <?php
                /* translators: %s: money amount. */
                echo esc_html( sprintf( __( 'worth %s off your next order', 'yayloyalty' ), LauncherCopy::money( $yayloyalty_card_member['worth'] ) ) );
                ?>
            </p>
        <?php endif; ?>
        <?php if ( null !== $yayloyalty_card['expires_at'] && $yayloyalty_card_member['balance'] > 0 ) : ?>
            <p>
                <?php
                /* translators: 1: the store's points label, as "Points", 2: date. */
                echo esc_html( sprintf( __( 'Your %1$s expire on %2$s', 'yayloyalty' ), $yayloyalty_card_label, Dates::to_site_date( $yayloyalty_card['expires_at'] ) ) );
                ?>
            </p>
        <?php endif; ?>
        <?php if ( null !== $yayloyalty_card_member['worth'] ) : ?>
            <p><a class="woocommerce-Button button" href="<?php echo esc_url( $yayloyalty_card_payload['checkout_url'] ); ?>"><?php esc_html_e( 'Use points at checkout', 'yayloyalty' ); ?></a></p>
        <?php endif; ?>
    <?php endif; ?>
</section>
