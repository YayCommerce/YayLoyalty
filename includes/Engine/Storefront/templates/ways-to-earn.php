<?php
/**
 * "Ways to earn": the Rewards tab's list, also placed by [yayloyalty_ways_to_earn] and its block.
 *
 * @var array $yayloyalty_earn LauncherEarnRules::for_viewer().
 */

defined( 'ABSPATH' ) || exit;

if ( [] === $yayloyalty_earn['rows'] && '' === $yayloyalty_earn['limit_line'] ) {
    return;
}
?>
<section>
    <h3><?php esc_html_e( 'Ways to earn', 'yayloyalty' ); ?></h3>
    <?php if ( [] !== $yayloyalty_earn['rows'] ) : ?>
        <ul class="yayloyalty-account__list">
            <?php foreach ( $yayloyalty_earn['rows'] as $yayloyalty_earn_row ) : ?>
                <li>
                    <span><?php echo esc_html( $yayloyalty_earn_row['label'] ); ?></span>
                    <strong><?php echo esc_html( $yayloyalty_earn_row['value'] ); ?></strong>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?php if ( '' !== $yayloyalty_earn['limit_line'] ) : ?>
        <p><?php echo esc_html( $yayloyalty_earn['limit_line'] ); ?></p>
    <?php endif; ?>
</section>
