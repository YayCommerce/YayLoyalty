<?php
/**
 * My Account → Rewards: the balance card, ways to earn and redeem, then the points history.
 *
 * @var array $view AccountRewards::view().
 */

use YayLoyalty\Engine\Storefront\AccountHistoryCsv;
use YayLoyalty\Engine\Storefront\AccountRewards;
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
$yayloyalty_history  = $view['history'];
$yayloyalty_first    = ( $yayloyalty_history['page'] - 1 ) * AccountRewards::PER_PAGE + 1;
$yayloyalty_last     = $yayloyalty_first + count( $yayloyalty_history['rows'] ) - 1;
$yayloyalty_date     = (string) get_option( 'date_format' );
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
                echo esc_html( sprintf( __( 'Your %1$s expire on %2$s', 'yayloyalty' ), SettingsHelper::points_label( 0 ), Dates::to_site_tz( $view['expires_at'], $yayloyalty_date ) ) );
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

    <section>
        <h3>
            <?php
            /* translators: %s: the store's points label, as "Points". */
            echo esc_html( sprintf( __( '%s history', 'yayloyalty' ), SettingsHelper::points_label( 0 ) ) );
            ?>
        </h3>
        <p class="yayloyalty-account__filters">
            <?php foreach ( AccountRewards::filter_labels() as $yayloyalty_filter => $yayloyalty_filter_label ) : ?>
                <a href="<?php echo esc_url( AccountRewards::url( 1, $yayloyalty_filter ) ); ?>"<?php echo $yayloyalty_filter === $yayloyalty_history['filter'] ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $yayloyalty_filter_label ); ?></a>
            <?php endforeach; ?>
            <?php if ( 'all' !== $yayloyalty_history['filter'] || $yayloyalty_history['total'] > 0 ) : ?>
                <a class="yayloyalty-account__download" href="<?php echo esc_url( AccountHistoryCsv::url() ); ?>"><?php esc_html_e( 'Download my history (CSV)', 'yayloyalty' ); ?></a>
            <?php endif; ?>
        </p>

        <?php if ( [] === $yayloyalty_history['rows'] ) : ?>
            <p>
                <?php
                /* translators: %s: the store's points label, as "Points". */
                echo esc_html( sprintf( __( 'No %s activity yet.', 'yayloyalty' ), SettingsHelper::points_label( 0 ) ) );
                ?>
            </p>
        <?php else : ?>
            <table class="woocommerce-orders-table shop_table shop_table_responsive">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e( 'Date', 'yayloyalty' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Description', 'yayloyalty' ); ?></th>
                        <th scope="col"><?php echo esc_html( SettingsHelper::points_label( 0 ) ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Balance', 'yayloyalty' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $yayloyalty_history['rows'] as $yayloyalty_row ) : ?>
                        <tr>
                            <td data-title="<?php esc_attr_e( 'Date', 'yayloyalty' ); ?>"><?php echo esc_html( Dates::to_site_tz( $yayloyalty_row['created_at'], $yayloyalty_date ) ); ?></td>
                            <td data-title="<?php esc_attr_e( 'Description', 'yayloyalty' ); ?>">
                                <?php echo esc_html( $yayloyalty_row['label'] ); ?>
                                <?php if ( '' !== $yayloyalty_row['order'] ) : ?>
                                    <br><small>
                                        <?php
                                        /* translators: %s: order number. */
                                        echo esc_html( sprintf( __( 'Order #%s', 'yayloyalty' ), $yayloyalty_row['order'] ) );
                                        ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td data-title="<?php echo esc_attr( SettingsHelper::points_label( 0 ) ); ?>"><?php echo esc_html( LauncherCopy::signed_points( $yayloyalty_row['points'] ) ); ?></td>
                            <td data-title="<?php esc_attr_e( 'Balance', 'yayloyalty' ); ?>"><?php echo esc_html( LauncherCopy::points( $yayloyalty_row['balance'] ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ( $yayloyalty_history['total'] > 0 && [] !== $yayloyalty_history['rows'] ) : ?>
            <div class="yayloyalty-account__pagination">
                <span>
                    <?php
                    /* translators: 1: first row number, 2: last row number, 3: total rows. */
                    echo esc_html( sprintf( __( '%1$s–%2$s of %3$s', 'yayloyalty' ), LauncherCopy::points( $yayloyalty_first ), LauncherCopy::points( $yayloyalty_last ), LauncherCopy::points( $yayloyalty_history['total'] ) ) );
                    ?>
                </span>
                <?php if ( $yayloyalty_history['page'] > 1 ) : ?>
                    <a class="woocommerce-button woocommerce-Button button" href="<?php echo esc_url( AccountRewards::url( $yayloyalty_history['page'] - 1, $yayloyalty_history['filter'] ) ); ?>"><?php esc_html_e( '‹ Previous', 'yayloyalty' ); ?></a>
                <?php endif; ?>
                <?php if ( $yayloyalty_last < $yayloyalty_history['total'] ) : ?>
                    <a class="woocommerce-button woocommerce-Button button" href="<?php echo esc_url( AccountRewards::url( $yayloyalty_history['page'] + 1, $yayloyalty_history['filter'] ) ); ?>"><?php esc_html_e( 'Next ›', 'yayloyalty' ); ?></a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
