<?php
/**
 * "Points history" on the Rewards tab: the CSV link, the filters, the table or its empty state, and the pages.
 * Styled by assets/storefront/account/points-history.css.
 *
 * @var array $yayloyalty_history AccountRewards::view()'s `history`.
 */

use YayLoyalty\Engine\Storefront\AccountHistoryCsv;
use YayLoyalty\Engine\Storefront\AccountRewards;
use YayLoyalty\Engine\Storefront\AccountRewardsIcon;
use YayLoyalty\Engine\Storefront\LauncherCopy;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

$yayloyalty_label     = SettingsHelper::points_label_plural();
$yayloyalty_columns   = AccountRewards::column_labels();
$yayloyalty_page      = $yayloyalty_history['page'];
$yayloyalty_filter_on = $yayloyalty_history['filter'];
$yayloyalty_pages     = (int) ceil( $yayloyalty_history['total'] / AccountRewards::PER_PAGE );
$yayloyalty_first     = ( $yayloyalty_page - 1 ) * AccountRewards::PER_PAGE + 1;
$yayloyalty_last      = $yayloyalty_first + count( $yayloyalty_history['rows'] ) - 1;
$yayloyalty_has_rows  = 'all' !== $yayloyalty_filter_on || $yayloyalty_history['total'] > 0;
$yayloyalty_download  = AccountRewardsIcon::get( 'download' ) . esc_html__( 'Export CSV', 'yayloyalty' );
?>
<section class="yayloyalty-account__section" id="<?php echo esc_attr( AccountRewards::HISTORY_ANCHOR ); ?>">
    <div class="yayloyalty-account__section-head">
        <h3 class="yayloyalty-account__title">
            <?php
            /* translators: %s: the store's points label, as "Points". */
            echo esc_html( sprintf( __( '%s history', 'yayloyalty' ), $yayloyalty_label ) );
            ?>
        </h3>
        <?php if ( $yayloyalty_has_rows ) : ?>
            <a class="yayloyalty-account__download" href="<?php echo esc_url( AccountHistoryCsv::url() ); ?>"><?php echo $yayloyalty_download; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed SVG and escaped text. ?></a>
        <?php else : ?>
            <span class="yayloyalty-account__download" aria-disabled="true"><?php echo $yayloyalty_download; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed SVG and escaped text. ?></span>
        <?php endif; ?>
    </div>

    <nav class="yayloyalty-account__tabs" aria-label="<?php esc_attr_e( 'Filter history', 'yayloyalty' ); ?>">
        <?php foreach ( AccountRewards::filter_labels() as $yayloyalty_filter => $yayloyalty_filter_label ) : ?>
            <a class="yayloyalty-account__tab" href="<?php echo esc_url( AccountRewards::url( 1, $yayloyalty_filter ) ); ?>"<?php echo $yayloyalty_filter === $yayloyalty_filter_on ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $yayloyalty_filter_label ); ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ( [] === $yayloyalty_history['rows'] ) : ?>
        <?php
        $yayloyalty_empty = [
            /* translators: %s: the store's points label, as "Points". */
            'all'    => [ __( 'No %s activity yet', 'yayloyalty' ), __( 'Your rewards activity will appear here.', 'yayloyalty' ), __( 'Make a purchase to start earning %s.', 'yayloyalty' ) ],
            /* translators: %s: the store's points label, as "Points". */
            'earn'   => [ __( 'No earned %s yet', 'yayloyalty' ), __( '%s you earn from orders, reviews and other rewards will appear here.', 'yayloyalty' ), '' ],
            /* translators: %s: the store's points label, as "Points". */
            'redeem' => [ __( 'No spent %s yet', 'yayloyalty' ), __( '%s you redeem for discounts will appear here.', 'yayloyalty' ), '' ],
            /* translators: %s: the store's points label, as "Points". */
            'expire' => [ __( 'No expired %s', 'yayloyalty' ), __( 'None of your %s have expired.', 'yayloyalty' ), '' ],
        ];
        $yayloyalty_empty = $yayloyalty_empty[ $yayloyalty_filter_on ] ?? $yayloyalty_empty['all'];
        ?>
        <div class="yayloyalty-account__empty">
            <span class="yayloyalty-account__empty-icon"><?php echo AccountRewardsIcon::empty_history(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed SVG. ?></span>
            <p class="yayloyalty-account__empty-title"><?php echo esc_html( sprintf( $yayloyalty_empty[0], $yayloyalty_label ) ); ?></p>
            <p class="yayloyalty-account__empty-text">
                <?php echo esc_html( sprintf( $yayloyalty_empty[1], $yayloyalty_label ) ); ?>
                <?php if ( '' !== $yayloyalty_empty[2] ) : ?>
                    <br>
                    <?php echo esc_html( sprintf( $yayloyalty_empty[2], $yayloyalty_label ) ); ?>
                <?php endif; ?>
            </p>
        </div>
    <?php else : ?>
        <div class="yayloyalty-account__table-wrap">
            <table class="yayloyalty-account__table">
                <thead>
                    <tr>
                        <th scope="col"><?php echo esc_html( $yayloyalty_columns['date'] ); ?></th>
                        <th scope="col"><?php echo esc_html( $yayloyalty_columns['description'] ); ?></th>
                        <th scope="col" class="yayloyalty-account__num"><?php echo esc_html( $yayloyalty_columns['points'] ); ?></th>
                        <th scope="col" class="yayloyalty-account__num"><?php echo esc_html( $yayloyalty_columns['balance'] ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $yayloyalty_history['rows'] as $yayloyalty_row ) : ?>
                        <?php $yayloyalty_tone = LedgerType::EXPIRE === $yayloyalty_row['type'] ? 'expire' : ( $yayloyalty_row['points'] > 0 ? 'plus' : 'minus' ); ?>
                        <tr>
                            <td class="yayloyalty-account__date" data-title="<?php echo esc_attr( $yayloyalty_columns['date'] ); ?>"><?php echo esc_html( Dates::to_site_date( $yayloyalty_row['created_at'] ) ); ?></td>
                            <td data-title="<?php echo esc_attr( $yayloyalty_columns['description'] ); ?>">
                                <?php echo esc_html( $yayloyalty_row['label'] ); ?>
                                <?php if ( '' !== $yayloyalty_row['order'] ) : ?>
                                    <?php
                                    /* translators: %s: order number. */
                                    $yayloyalty_order = sprintf( __( 'Order #%s', 'yayloyalty' ), $yayloyalty_row['order'] );
                                    ?>
                                    <?php if ( '' !== $yayloyalty_row['order_url'] ) : ?>
                                        <a class="yayloyalty-account__order" href="<?php echo esc_url( $yayloyalty_row['order_url'] ); ?>"><?php echo esc_html( $yayloyalty_order ); ?></a>
                                    <?php else : ?>
                                        <span class="yayloyalty-account__order"><?php echo esc_html( $yayloyalty_order ); ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td class="yayloyalty-account__num" data-title="<?php echo esc_attr( $yayloyalty_columns['points'] ); ?>"><span class="yayloyalty-account__badge yayloyalty-account__badge--<?php echo esc_attr( $yayloyalty_tone ); ?>"><?php echo esc_html( LauncherCopy::signed_points( $yayloyalty_row['points'] ) ); ?></span></td>
                            <td class="yayloyalty-account__num" data-title="<?php echo esc_attr( $yayloyalty_columns['balance'] ); ?>"><?php echo esc_html( LauncherCopy::points( $yayloyalty_row['balance'] ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="yayloyalty-account__pagination">
            <span class="yayloyalty-account__range">
                <?php
                /* translators: 1: first row number, 2: last row number, 3: total rows. */
                echo esc_html( sprintf( __( '%1$s–%2$s of %3$s activities', 'yayloyalty' ), LauncherCopy::points( $yayloyalty_first ), LauncherCopy::points( $yayloyalty_last ), LauncherCopy::points( $yayloyalty_history['total'] ) ) );
                ?>
            </span>
            <?php if ( $yayloyalty_pages > 1 ) : ?>
                <?php require __DIR__ . '/points-history-pages.php'; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>
