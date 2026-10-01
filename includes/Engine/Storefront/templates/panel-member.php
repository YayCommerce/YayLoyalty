<?php
/**
 * Signed-in panel body (1b; 1e on mobile): balance first, then reference material.
 *
 * @var array $view      Launcher::build_view().
 * @var array $yayloyalty_text   The Design tab's storefront_text.
 * @var array $yayloyalty_member { balance: int, worth: ?float }.
 */

use YayLoyalty\Controllers\StorefrontActivityRestController;
use YayLoyalty\Engine\Storefront\LauncherCopy;
use YayLoyalty\Engine\Storefront\UiClasses;
use YayLoyalty\Enums\CampaignTrigger;

defined( 'ABSPATH' ) || exit;

$yayloyalty_earn   = $view['earn'];
$yayloyalty_redeem = $view['redeem'];
// Flat-points rules share one row shape; purchase states a rate, so it is written out above the loop.
$yayloyalty_flat_rows = [
    CampaignTrigger::SIGNUP => __( 'Create an account', 'yayloyalty' ),
    CampaignTrigger::REVIEW => __( 'Write a review', 'yayloyalty' ),
];
?>
<section class="yl:flex yl:flex-col yl:gap-1 yl:rounded-lg yl:bg-muted yl:p-4">
    <p class="<?php echo esc_attr( UiClasses::DESCRIPTION ); ?>"><?php echo esc_html( $yayloyalty_text['balance_heading'] ); ?></p>
    <p class="yl:m-0 yl:text-3xl yl:font-semibold yl:leading-none yl:tabular-nums yl:text-foreground"><?php echo esc_html( LauncherCopy::points( $yayloyalty_member['balance'] ) ); ?></p>
    <?php if ( null !== $yayloyalty_member['worth'] ) : ?>
        <p class="<?php echo esc_attr( UiClasses::DESCRIPTION ); ?>">
            <?php
            /* translators: %s: money amount. */
            echo esc_html( sprintf( __( 'worth %s', 'yayloyalty' ), LauncherCopy::money( $yayloyalty_member['worth'] ) ) );
            ?>
        </p>
    <?php endif; ?>
</section>

<?php if ( array_filter( $yayloyalty_earn ) ) : ?>
    <section class="yl:flex yl:flex-col yl:gap-2">
        <h3 class="<?php echo esc_attr( UiClasses::SECTION_HEADING ); ?>"><?php esc_html_e( 'Ways to earn', 'yayloyalty' ); ?></h3>
        <ul class="yl:m-0 yl:flex yl:flex-col yl:gap-0 yl:list-none yl:p-0">
            <?php if ( null !== $yayloyalty_earn[ CampaignTrigger::PURCHASE ] ) : ?>
                <li class="yl:flex yl:items-center yl:justify-between yl:gap-3 yl:py-2">
                    <span class="yl:font-medium"><?php esc_html_e( 'Make a purchase', 'yayloyalty' ); ?></span>
                    <span class="yl:text-muted-foreground yl:text-right"><?php echo esc_html( LauncherCopy::earn_rate( $yayloyalty_text['earn_rate_line'], $yayloyalty_earn[ CampaignTrigger::PURCHASE ] ) ); ?></span>
                </li>
            <?php endif; ?>
            <?php foreach ( $yayloyalty_flat_rows as $yayloyalty_trigger => $yayloyalty_label ) : ?>
                <?php if ( null !== $yayloyalty_earn[ $yayloyalty_trigger ] ) : ?>
                    <li class="yl:flex yl:items-center yl:justify-between yl:gap-3 yl:py-2">
                        <span class="yl:font-medium"><?php echo esc_html( $yayloyalty_label ); ?></span>
                        <span class="<?php echo esc_attr( UiClasses::badge( 'primary-soft' ) ); ?>">
                            <?php
                            /* translators: %s: points. */
                            echo esc_html( sprintf( __( '%s points', 'yayloyalty' ), LauncherCopy::points( $yayloyalty_earn[ $yayloyalty_trigger ] ) ) );
                            ?>
                        </span>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<?php if ( null !== $yayloyalty_redeem ) : ?>
    <section class="yl:flex yl:flex-col yl:gap-2">
        <h3 class="<?php echo esc_attr( UiClasses::SECTION_HEADING ); ?>"><?php esc_html_e( 'Ways to redeem', 'yayloyalty' ); ?></h3>
        <div class="<?php echo esc_attr( UiClasses::CARD ); ?> yl:gap-1">
            <div class="yl:flex yl:items-center yl:justify-between yl:gap-3">
                <span class="yl:font-medium"><?php esc_html_e( 'Points for discount', 'yayloyalty' ); ?></span>
                <span class="yl:text-muted-foreground"><?php echo esc_html( LauncherCopy::redeem_rate( $yayloyalty_redeem['points_per_unit'] ) ); ?></span>
            </div>
            <p class="<?php echo esc_attr( UiClasses::DESCRIPTION ); ?> yl:text-xs"><?php echo esc_html( LauncherCopy::redeem_limits( $yayloyalty_redeem['min_points'], $yayloyalty_redeem['max_percent'] ) ); ?></p>
        </div>
    </section>
<?php endif; ?>

<section class="yl:flex yl:flex-col yl:gap-2" data-yl-activity aria-live="polite">
    <h3 class="<?php echo esc_attr( UiClasses::SECTION_HEADING ); ?>"><?php esc_html_e( 'Recent activity', 'yayloyalty' ); ?></h3>
    <ul class="yl:m-0 yl:flex yl:flex-col yl:list-none yl:p-0" data-yl-activity-list>
        <?php for ( $yayloyalty_i = 0; $yayloyalty_i < StorefrontActivityRestController::ROWS; $yayloyalty_i++ ) : ?>
            <li class="yl:flex yl:items-center yl:justify-between yl:gap-3 yl:py-2" aria-hidden="true">
                <span class="<?php echo esc_attr( UiClasses::SKELETON ); ?> yl:h-4 yl:w-40"></span>
                <span class="<?php echo esc_attr( UiClasses::SKELETON ); ?> yl:h-4 yl:w-10"></span>
            </li>
        <?php endfor; ?>
    </ul>
    <template data-yl-activity-row>
        <li class="yl:flex yl:items-center yl:justify-between yl:gap-3 yl:py-2">
            <span class="yl:flex yl:flex-col yl:gap-0.5 yl:min-w-0">
                <span class="yl:font-medium yl:truncate" data-yl-label></span>
                <span class="yl:text-muted-foreground yl:text-xs" data-yl-meta></span>
            </span>
            <span class="yl:font-semibold yl:tabular-nums" data-yl-delta></span>
        </li>
    </template>
    <p class="<?php echo esc_attr( UiClasses::DESCRIPTION ); ?>" data-yl-activity-empty hidden><?php esc_html_e( 'No points activity yet.', 'yayloyalty' ); ?></p>
    <p class="<?php echo esc_attr( UiClasses::DESCRIPTION ); ?>" data-yl-activity-error hidden><?php esc_html_e( 'Your recent activity could not be loaded. Try again later.', 'yayloyalty' ); ?></p>
</section>
