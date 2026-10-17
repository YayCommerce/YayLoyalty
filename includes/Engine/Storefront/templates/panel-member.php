<?php
/**
 * Signed-in panel body: balance first, then reference material, each block ruled off.
 *
 * @var array $view      Launcher::view().
 * @var array $yayloyalty_text   The Design tab's storefront_text.
 * @var array $yayloyalty_member { balance: int, worth: ?float }.
 */

use YayLoyalty\Controllers\StorefrontActivityRestController;
use YayLoyalty\Engine\Storefront\LauncherCopy;
use YayLoyalty\Engine\Storefront\LauncherEarnRules;
use YayLoyalty\Engine\Storefront\UiClasses;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Helpers\SettingsHelper;

defined( 'ABSPATH' ) || exit;

$yayloyalty_earn       = LauncherEarnRules::with_daily_limit( LauncherEarnRules::member_rows( $view['earn'], $yayloyalty_text['earn_rate_line'], $view['purchase_min'] ), $view['left_today'] );
$yayloyalty_earn_rows  = $yayloyalty_earn['rows'];
$yayloyalty_limit_line = $yayloyalty_earn['limit_line'];
// Rows already in hand are printed; null leaves skeletons for launcher.js to replace.
$yayloyalty_activity = $view['activity'] ?? null;
?>
<section class="yl:flex yl:flex-col yl:gap-0.5 yl:border-b yl:border-border yl:p-4">
    <p class="yl:m-0 yl:text-[11px] yl:uppercase yl:tracking-[0.06em] yl:text-muted-foreground" data-yl-text="balance_heading"><?php echo esc_html( $yayloyalty_text['balance_heading'] ); ?></p>
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

<?php if ( [] !== $yayloyalty_earn_rows || '' !== $yayloyalty_limit_line ) : ?>
    <section class="<?php echo esc_attr( UiClasses::PANEL_SECTION ); ?>">
        <h3 class="<?php echo esc_attr( UiClasses::SECTION_HEADING ); ?>"><?php esc_html_e( 'Ways to earn', 'yayloyalty' ); ?></h3>
        <?php if ( [] !== $yayloyalty_earn_rows ) : ?>
            <ul class="yl:m-0 yl:flex yl:flex-col yl:list-none yl:p-0">
                <?php foreach ( $yayloyalty_earn_rows as $yayloyalty_earn_row ) : ?>
                    <li class="<?php echo esc_attr( UiClasses::PANEL_ROW ); ?>">
                        <span><?php echo esc_html( $yayloyalty_earn_row['label'] ); ?></span>
                        <?php if ( CampaignTrigger::PURCHASE === $yayloyalty_earn_row['trigger'] ) : ?>
                            <span class="yl:text-right yl:font-semibold" data-yl-text="earn_rate_line"><?php echo esc_html( $yayloyalty_earn_row['value'] ); ?></span>
                        <?php else : ?>
                            <span class="yl:whitespace-nowrap yl:font-semibold"><?php echo esc_html( $yayloyalty_earn_row['value'] ); ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if ( '' !== $yayloyalty_limit_line ) : ?>
            <p class="yl:m-0 yl:text-xs yl:text-muted-foreground"><?php echo esc_html( $yayloyalty_limit_line ); ?></p>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ( null !== $view['redeem'] ) : ?>
    <section class="<?php echo esc_attr( UiClasses::PANEL_SECTION ); ?>">
        <h3 class="<?php echo esc_attr( UiClasses::SECTION_HEADING ); ?>"><?php esc_html_e( 'Ways to redeem', 'yayloyalty' ); ?></h3>
        <ul class="yl:m-0 yl:flex yl:flex-col yl:list-none yl:p-0">
            <li class="<?php echo esc_attr( UiClasses::PANEL_ROW ); ?>">
                <span>
                    <?php
                    /* translators: %s: the store's points label, as "Points". */
                    echo esc_html( sprintf( __( '%s for discount', 'yayloyalty' ), SettingsHelper::points_label( 0 ) ) );
                    ?>
                </span>
                <span class="yl:whitespace-nowrap yl:font-semibold"><?php echo esc_html( LauncherCopy::redeem_rate( $view['redeem']['points_per_unit'] ) ); ?></span>
            </li>
        </ul>
        <p class="yl:m-0 yl:text-xs yl:text-muted-foreground"><?php echo esc_html( LauncherCopy::redeem_limits( $view['redeem'] ) ); ?></p>
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
    <p class="yl:m-0 yl:text-xs yl:text-muted-foreground" data-yl-activity-empty <?php echo [] === $yayloyalty_activity ? '' : 'hidden'; ?>>
        <?php echo esc_html( LauncherCopy::no_activity() ); ?>
    </p>
    <p class="yl:m-0 yl:text-xs yl:text-muted-foreground" data-yl-activity-error hidden><?php esc_html_e( 'Your recent activity could not be loaded. Try again later.', 'yayloyalty' ); ?></p>
</section>
