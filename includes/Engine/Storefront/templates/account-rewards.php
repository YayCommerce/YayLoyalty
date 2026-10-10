<?php
/**
 * My Account → Rewards: the balance card, ways to earn and redeem, then the points history.
 *
 * @var array $view AccountRewards::view().
 */

use YayLoyalty\Engine\Storefront\AccountHistoryCsv;
use YayLoyalty\Engine\Storefront\AccountRewards;
use YayLoyalty\Engine\Storefront\LauncherCopy;
use YayLoyalty\Engine\Storefront\LauncherEarnRules;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

$yayloyalty_payload = $view['payload'];
$yayloyalty_text    = $yayloyalty_payload['text'];
$yayloyalty_balance = $yayloyalty_payload['member']['balance'];
$yayloyalty_worth   = $yayloyalty_payload['member']['worth'];
// Null while redemption is off: worth, the redeem column and the checkout link stay hidden with it.
$yayloyalty_redeem    = $yayloyalty_payload['redeem'];
$yayloyalty_earn_rows = $view['can_earn'] ? LauncherEarnRules::member_rows( $yayloyalty_payload['earn'], $yayloyalty_text['earn_rate_line'], $yayloyalty_payload['purchase_min'] ) : [];
$yayloyalty_history   = $view['history'];
$yayloyalty_columns   = AccountRewards::column_labels();
$yayloyalty_first     = ( $yayloyalty_history['page'] - 1 ) * AccountRewards::PER_PAGE + 1;
$yayloyalty_last      = $yayloyalty_first + count( $yayloyalty_history['rows'] ) - 1;
// 0 picks the plural label.
$yayloyalty_label = SettingsHelper::points_label( 0 );
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
                echo esc_html( sprintf( __( 'Your %1$s expire on %2$s', 'yayloyalty' ), $yayloyalty_label, Dates::to_site_date( $view['expires_at'] ) ) );
                ?>
            </p>
        <?php endif; ?>
        <?php if ( null !== $yayloyalty_worth ) : ?>
            <p><a class="woocommerce-Button button" href="<?php echo esc_url( $yayloyalty_payload['checkout_url'] ); ?>"><?php esc_html_e( 'Use points at checkout', 'yayloyalty' ); ?></a></p>
        <?php endif; ?>
    </section>

    <?php if ( [] !== $yayloyalty_earn_rows || null !== $yayloyalty_redeem ) : ?>
        <div class="yayloyalty-account__rules">
            <?php if ( [] !== $yayloyalty_earn_rows ) : ?>
                <section>
                    <h3><?php esc_html_e( 'Ways to earn', 'yayloyalty' ); ?></h3>
                    <ul class="yayloyalty-account__list">
                        <?php foreach ( $yayloyalty_earn_rows as $yayloyalty_earn_row ) : ?>
                            <li>
                                <span><?php echo esc_html( $yayloyalty_earn_row['label'] ); ?></span>
                                <strong><?php echo esc_html( $yayloyalty_earn_row['value'] ); ?></strong>
                            </li>
                        <?php endforeach; ?>
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
                                echo esc_html( sprintf( __( '%s for discount', 'yayloyalty' ), $yayloyalty_label ) );
                                ?>
                            </span>
                            <strong><?php echo esc_html( LauncherCopy::redeem_rate( $yayloyalty_redeem['points_per_unit'] ) ); ?></strong>
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
            echo esc_html( sprintf( __( '%s history', 'yayloyalty' ), $yayloyalty_label ) );
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
            <p><?php echo esc_html( LauncherCopy::no_activity() ); ?></p>
        <?php else : ?>
            <table class="woocommerce-orders-table shop_table shop_table_responsive">
                <thead>
                    <tr>
                        <th scope="col"><?php echo esc_html( $yayloyalty_columns['date'] ); ?></th>
                        <th scope="col"><?php echo esc_html( $yayloyalty_columns['description'] ); ?></th>
                        <th scope="col"><?php echo esc_html( $yayloyalty_columns['points'] ); ?></th>
                        <th scope="col"><?php echo esc_html( $yayloyalty_columns['balance'] ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $yayloyalty_history['rows'] as $yayloyalty_row ) : ?>
                        <tr>
                            <td data-title="<?php echo esc_attr( $yayloyalty_columns['date'] ); ?>"><?php echo esc_html( Dates::to_site_date( $yayloyalty_row['created_at'] ) ); ?></td>
                            <td data-title="<?php echo esc_attr( $yayloyalty_columns['description'] ); ?>">
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
                            <td data-title="<?php echo esc_attr( $yayloyalty_columns['points'] ); ?>"><?php echo esc_html( LauncherCopy::signed_points( $yayloyalty_row['points'] ) ); ?></td>
                            <td data-title="<?php echo esc_attr( $yayloyalty_columns['balance'] ); ?>"><?php echo esc_html( LauncherCopy::points( $yayloyalty_row['balance'] ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ( [] !== $yayloyalty_history['rows'] ) : ?>
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
