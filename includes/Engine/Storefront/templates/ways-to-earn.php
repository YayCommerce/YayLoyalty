<?php
/**
 * "Ways to earn": the Rewards tab's list, also in the launcher panel and placed by [yayloyalty_ways_to_earn] and its block.
 * Styled by assets/storefront/account/ways-to-earn.css; each row by rule-list-item.php.
 *
 * @var array $yayloyalty_earn LauncherEarnRules::for_viewer().
 */

use YayLoyalty\Engine\Storefront\LauncherEarnRules;

defined( 'ABSPATH' ) || exit;

if ( LauncherEarnRules::is_empty( $yayloyalty_earn ) ) {
    return;
}
?>
<section class="yayloyalty-account__section yayloyalty-account__earn">
    <h3 class="yayloyalty-account__title"><?php esc_html_e( 'Ways to earn', 'yayloyalty' ); ?></h3>
    <?php if ( [] !== $yayloyalty_earn['rows'] ) : ?>
        <ul class="yayloyalty-account__list">
            <?php
            foreach ( $yayloyalty_earn['rows'] as $yayloyalty_earn_row ) {
                $yayloyalty_item = LauncherEarnRules::list_item( $yayloyalty_earn_row );

                require __DIR__ . '/rule-list-item.php';
            }
            ?>
        </ul>
    <?php endif; ?>
    <?php if ( '' !== $yayloyalty_earn['limit_line'] ) : ?>
        <p class="yayloyalty-account__hint"><?php echo esc_html( $yayloyalty_earn['limit_line'] ); ?></p>
    <?php endif; ?>
</section>
