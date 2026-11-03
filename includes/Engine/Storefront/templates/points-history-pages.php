<?php
/**
 * The history's page links: previous, the first and last page and those beside the current one, then next.
 * Styled by assets/storefront/account/points-history-pages.css.
 *
 * @var int    $yayloyalty_page      The current page.
 * @var int    $yayloyalty_pages     How many pages there are, more than one.
 * @var string $yayloyalty_filter_on The history filter the links keep.
 */

use YayLoyalty\Engine\Storefront\AccountMyPoints;
use YayLoyalty\Engine\Storefront\AccountMyPointsIcon;
use YayLoyalty\Utils\Kses;

defined( 'ABSPATH' ) || exit;

$yayloyalty_shown = [];

for ( $yayloyalty_p = 1; $yayloyalty_p <= $yayloyalty_pages; $yayloyalty_p++ ) {
    if ( 1 === $yayloyalty_p || $yayloyalty_pages === $yayloyalty_p || abs( $yayloyalty_p - $yayloyalty_page ) <= 1 ) {
        $yayloyalty_shown[] = $yayloyalty_p;
    }
}
?>
<nav class="yayloyalty-account__pages" aria-label="<?php esc_attr_e( 'History pages', 'yayloyalty' ); ?>">
    <?php if ( $yayloyalty_page > 1 ) : ?>
        <a class="yayloyalty-account__page" href="<?php echo esc_url( AccountMyPoints::url( $yayloyalty_page - 1, $yayloyalty_filter_on ) ); ?>" data-page="<?php echo esc_attr( $yayloyalty_page - 1 ); ?>" data-filter="<?php echo esc_attr( $yayloyalty_filter_on ); ?>" aria-label="<?php esc_attr_e( 'Previous page', 'yayloyalty' ); ?>"><?php echo wp_kses( AccountMyPointsIcon::get( 'prev' ), Kses::svg() ); ?></a>
    <?php else : ?>
        <span class="yayloyalty-account__page" aria-disabled="true"><?php echo wp_kses( AccountMyPointsIcon::get( 'prev' ), Kses::svg() ); ?></span>
    <?php endif; ?>

    <?php foreach ( $yayloyalty_shown as $yayloyalty_index => $yayloyalty_p ) : ?>
        <?php if ( $yayloyalty_index > 0 && $yayloyalty_p - $yayloyalty_shown[ $yayloyalty_index - 1 ] > 1 ) : ?>
            <span class="yayloyalty-account__page-gap" aria-hidden="true">…</span>
        <?php endif; ?>
        <?php if ( $yayloyalty_p === $yayloyalty_page ) : ?>
            <span class="yayloyalty-account__page" aria-current="page"><?php echo esc_html( number_format_i18n( $yayloyalty_p ) ); ?></span>
        <?php else : ?>
            <a class="yayloyalty-account__page" href="<?php echo esc_url( AccountMyPoints::url( $yayloyalty_p, $yayloyalty_filter_on ) ); ?>" data-page="<?php echo esc_attr( $yayloyalty_p ); ?>" data-filter="<?php echo esc_attr( $yayloyalty_filter_on ); ?>"><?php echo esc_html( number_format_i18n( $yayloyalty_p ) ); ?></a>
        <?php endif; ?>
    <?php endforeach; ?>

    <?php if ( $yayloyalty_page < $yayloyalty_pages ) : ?>
        <a class="yayloyalty-account__page" href="<?php echo esc_url( AccountMyPoints::url( $yayloyalty_page + 1, $yayloyalty_filter_on ) ); ?>" data-page="<?php echo esc_attr( $yayloyalty_page + 1 ); ?>" data-filter="<?php echo esc_attr( $yayloyalty_filter_on ); ?>" aria-label="<?php esc_attr_e( 'Next page', 'yayloyalty' ); ?>"><?php echo wp_kses( AccountMyPointsIcon::get( 'next' ), Kses::svg() ); ?></a>
    <?php else : ?>
        <span class="yayloyalty-account__page" aria-disabled="true"><?php echo wp_kses( AccountMyPointsIcon::get( 'next' ), Kses::svg() ); ?></span>
    <?php endif; ?>
</nav>
