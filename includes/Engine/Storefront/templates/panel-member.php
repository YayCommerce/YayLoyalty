<?php
/**
 * Signed-in panel body (1b; 1e on mobile): balance first, then reference material, each block ruled off.
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

$yayloyalty_earn = $view['earn'];
// No signup row: a signed-in shopper already has the account it pays for.
$yayloyalty_purchase = $yayloyalty_earn[ CampaignTrigger::PURCHASE ];
$yayloyalty_review   = $yayloyalty_earn[ CampaignTrigger::REVIEW ];
// Rows printed here (the Design tab's preview); null leaves skeletons for launcher.js to replace.
$yayloyalty_activity = $view['activity'] ?? null;
?>
<section class="yl:flex yl:flex-col yl:gap-0.5 yl:border-b yl:border-border yl:p-4">
    <p class="yl:m-0 yl:text-[11px] yl:uppercase yl:tracking-[0.06em] yl:text-muted-foreground"><?php echo esc_html( $yayloyalty_text['balance_heading'] ); ?></p>
    <p class="yl:m-0 yl:text-[30px] yl:font-semibold yl:leading-tight yl:tracking-[-0.01em] yl:tabular-nums yl:text-foreground"><?php echo esc_html( LauncherCopy::points( $yayloyalty_member['balance'] ) ); ?></p>
    <?php if ( null !== $yayloyalty_member['worth'] ) : ?>
        <p class="yl:m-0 yl:text-xs yl:text-muted-foreground">
            <?php
            /* translators: %s: money amount. */
            echo esc_html( sprintf( __( 'worth %s', 'yayloyalty' ), LauncherCopy::money( $yayloyalty_member['worth'] ) ) );
            ?>
        </p>
    <?php endif; ?>
</section>

<?php if ( null !== $yayloyalty_purchase || null !== $yayloyalty_review ) : ?>
    <section class="<?php echo esc_attr( UiClasses::PANEL_SECTION ); ?>">
        <h3 class="<?php echo esc_attr( UiClasses::SECTION_HEADING ); ?>"><?php esc_html_e( 'Ways to earn', 'yayloyalty' ); ?></h3>
        <ul class="yl:m-0 yl:flex yl:flex-col yl:list-none yl:p-0">
            <?php if ( null !== $yayloyalty_purchase ) : ?>
                <li class="<?php echo esc_attr( UiClasses::PANEL_ROW ); ?>">
                    <span><?php esc_html_e( 'Make a purchase', 'yayloyalty' ); ?></span>
                    <span class="yl:text-right yl:font-semibold"><?php echo esc_html( LauncherCopy::earn_rate( $yayloyalty_text['earn_rate_line'], $yayloyalty_purchase, $view['purchase_min'] ) ); ?></span>
                </li>
            <?php endif; ?>
            <?php if ( null !== $yayloyalty_review ) : ?>
                <li class="<?php echo esc_attr( UiClasses::PANEL_ROW ); ?>">
                    <span><?php esc_html_e( 'Write a review', 'yayloyalty' ); ?></span>
                    <span class="yl:whitespace-nowrap yl:font-semibold">
                        <?php
                        /* translators: %s: points. */
                        echo esc_html( sprintf( __( '%s points', 'yayloyalty' ), LauncherCopy::points( $yayloyalty_review ) ) );
                        ?>
                    </span>
                </li>
            <?php endif; ?>
        </ul>
    </section>
<?php endif; ?>

<section class="<?php echo esc_attr( UiClasses::PANEL_SECTION ); ?>" data-yl-activity aria-live="polite">
    <h3 class="<?php echo esc_attr( UiClasses::SECTION_HEADING ); ?>"><?php esc_html_e( 'Recent activity', 'yayloyalty' ); ?></h3>
    <ul class="yl:m-0 yl:flex yl:flex-col yl:list-none yl:p-0" data-yl-activity-list>
        <?php if ( null !== $yayloyalty_activity ) : ?>
            <?php foreach ( $yayloyalty_activity as $yayloyalty_item ) : ?>
                <?php include __DIR__ . '/activity-row.php'; ?>
            <?php endforeach; ?>
        <?php else : ?>
            <?php for ( $yayloyalty_i = 0; $yayloyalty_i < StorefrontActivityRestController::ROWS; $yayloyalty_i++ ) : ?>
                <li class="<?php echo esc_attr( UiClasses::PANEL_ROW ); ?>" aria-hidden="true">
                    <span class="<?php echo esc_attr( UiClasses::SKELETON ); ?> yl:h-4 yl:w-40"></span>
                    <span class="<?php echo esc_attr( UiClasses::SKELETON ); ?> yl:h-4 yl:w-10"></span>
                </li>
            <?php endfor; ?>
        <?php endif; ?>
    </ul>
    <template data-yl-activity-row>
        <?php
        $yayloyalty_item = null;
        require __DIR__ . '/activity-row.php';
        ?>
    </template>
    <p class="yl:m-0 yl:text-xs yl:text-muted-foreground" data-yl-activity-empty <?php echo [] === $yayloyalty_activity ? '' : 'hidden'; ?>><?php esc_html_e( 'No points activity yet.', 'yayloyalty' ); ?></p>
    <p class="yl:m-0 yl:text-xs yl:text-muted-foreground" data-yl-activity-error hidden><?php esc_html_e( 'Your recent activity could not be loaded. Try again later.', 'yayloyalty' ); ?></p>
</section>
