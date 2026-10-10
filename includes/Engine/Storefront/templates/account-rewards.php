<?php
/**
 * My Account → Rewards: the balance card, ways to earn and redeem, then the points history.
 *
 * @var array $view AccountRewards::view().
 */

use YayLoyalty\Engine\Storefront\LauncherCopy;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

$yayloyalty_payload = $view['payload'];
$yayloyalty_text    = $yayloyalty_payload['text'];
$yayloyalty_balance = $yayloyalty_payload['member']['balance'];
$yayloyalty_worth   = $yayloyalty_payload['member']['worth'];
// Null while redemption is off: worth, the redeem column and the checkout link stay hidden with it.
$yayloyalty_redeem   = $yayloyalty_payload['redeem'];
$yayloyalty_purchase = $view['can_earn'] ? $yayloyalty_payload['earn'][ CampaignTrigger::PURCHASE ] : null;
$yayloyalty_review   = $view['can_earn'] ? $yayloyalty_payload['earn'][ CampaignTrigger::REVIEW ] : null;
?>
<div class="yayloyalty-account">
    <section class="yayloyalty-account__card">
        <h2 class="yayloyalty-account__heading"><?php echo esc_html( $yayloyalty_text['balance_heading'] ); ?></h2>
        <p class="yayloyalty-account__balance"><?php echo esc_html( LauncherCopy::points( $yayloyalty_balance ) ); ?></p>
        <?php if ( null !== $yayloyalty_worth ) : ?>
            <p>
                <?php
                /* translators: %s: money amount. */
                echo esc_html( sprintf( __( 'worth %s off your next order', 'yayloyalty' ), LauncherCopy::money( $yayloyalty_worth ) ) );
                ?>
            </p>
        <?php endif; ?>
        <?php if ( null !== $view['expires_at'] && $yayloyalty_balance > 0 ) : ?>
            <p>
                <?php
                /* translators: 1: the store's points label, as "Points", 2: date. */
                echo esc_html( sprintf( __( 'Your %1$s expire on %2$s', 'yayloyalty' ), SettingsHelper::points_label( 0 ), Dates::to_site_tz( $view['expires_at'], (string) get_option( 'date_format' ) ) ) );
                ?>
            </p>
        <?php endif; ?>
        <?php if ( null !== $yayloyalty_redeem && null !== $yayloyalty_worth ) : ?>
            <p><a class="woocommerce-Button button" href="<?php echo esc_url( $yayloyalty_payload['checkout_url'] ); ?>"><?php esc_html_e( 'Use points at checkout', 'yayloyalty' ); ?></a></p>
        <?php endif; ?>
    </section>

    <?php if ( null !== $yayloyalty_purchase || null !== $yayloyalty_review || null !== $yayloyalty_redeem ) : ?>
        <div class="yayloyalty-account__rules">
            <?php if ( null !== $yayloyalty_purchase || null !== $yayloyalty_review ) : ?>
                <section>
                    <h3><?php esc_html_e( 'Ways to earn', 'yayloyalty' ); ?></h3>
                    <ul class="yayloyalty-account__list">
                        <?php if ( null !== $yayloyalty_purchase ) : ?>
                            <li>
                                <span><?php esc_html_e( 'Make a purchase', 'yayloyalty' ); ?></span>
                                <strong><?php echo esc_html( LauncherCopy::earn_rate( $yayloyalty_text['earn_rate_line'], $yayloyalty_purchase, $yayloyalty_payload['purchase_min'] ) ); ?></strong>
                            </li>
                        <?php endif; ?>
                        <?php if ( null !== $yayloyalty_review ) : ?>
                            <li>
                                <span><?php esc_html_e( 'Write a review', 'yayloyalty' ); ?></span>
                                <strong><?php echo esc_html( SettingsHelper::points_text( $yayloyalty_review ) ); ?></strong>
                            </li>
                        <?php endif; ?>
                    </ul>
                </section>
            <?php endif; ?>

            <?php if ( null !== $yayloyalty_redeem ) : ?>
                <section>
                    <h3><?php esc_html_e( 'Ways to redeem', 'yayloyalty' ); ?></h3>
                    <ul class="yayloyalty-account__list">
                        <li>
                            <span>
                                <?php
                                /* translators: %s: the store's points label, as "Points". */
                                echo esc_html( sprintf( __( '%s for discount', 'yayloyalty' ), SettingsHelper::points_label( 0 ) ) );
                                ?>
                            </span>
                            <strong><?php echo esc_html( sprintf( '%1$s %2$s = %3$s', LauncherCopy::rate( $yayloyalty_redeem['points_per_unit'] ), SettingsHelper::points_label( 0 ), LauncherCopy::money( 1.0 ) ) ); ?></strong>
                        </li>
                    </ul>
                    <?php if ( null !== $yayloyalty_worth ) : ?>
                        <p>
                            <?php
                            /* translators: %s: money amount. */
                            echo esc_html( sprintf( __( 'You could take %s off today.', 'yayloyalty' ), LauncherCopy::money( $yayloyalty_worth ) ) );
                            ?>
                        </p>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
