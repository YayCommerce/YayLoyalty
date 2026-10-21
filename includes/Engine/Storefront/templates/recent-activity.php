<?php
/**
 * The launcher panel's "Recent activity": rows printed when the view holds them (the Design tab's preview),
 * else skeletons launcher.js replaces from GET /me/activity. Styled by assets/storefront/account/recent-activity.css.
 * The data-yl-* hooks are launcher.js's; keep them.
 *
 * @var array $view Launcher::view(); `activity` the rows when already in hand.
 */

use YayLoyalty\Controllers\StorefrontActivityRestController;
use YayLoyalty\Engine\Storefront\AccountRewardsIcon;
use YayLoyalty\Engine\Storefront\LauncherCopy;
use YayLoyalty\Engine\Storefront\UiClasses;
use YayLoyalty\Helpers\SettingsHelper;

defined( 'ABSPATH' ) || exit;

$yayloyalty_activity = $view['activity'] ?? null;
$yayloyalty_label    = SettingsHelper::points_label_plural();
?>
<section class="yayloyalty-account__section yayloyalty-account__activity" data-yl-activity aria-live="polite">
    <div class="yayloyalty-account__section-head">
        <h3 class="yayloyalty-account__title"><?php esc_html_e( 'Recent activity', 'yayloyalty' ); ?></h3>
        <a class="yayloyalty-account__activity-all" href="<?php echo esc_url( $view['rewards_url'] ); ?>"><?php esc_html_e( 'View all', 'yayloyalty' ); ?></a>
    </div>
    <ul class="yayloyalty-account__activity-list" data-yl-activity-list<?php echo [] === $yayloyalty_activity ? ' hidden' : ''; ?>>
        <?php if ( null !== $yayloyalty_activity ) : ?>
            <?php foreach ( $yayloyalty_activity as $yayloyalty_item ) : ?>
                <?php require __DIR__ . '/activity-row.php'; ?>
            <?php endforeach; ?>
        <?php else : ?>
            <?php for ( $yayloyalty_i = 0; $yayloyalty_i < StorefrontActivityRestController::ROWS; $yayloyalty_i++ ) : ?>
                <li class="yayloyalty-account__activity-row" aria-hidden="true">
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
    <div class="yayloyalty-account__activity-empty" data-yl-activity-empty<?php echo [] === $yayloyalty_activity ? '' : ' hidden'; ?>>
        <span class="yayloyalty-account__activity-empty-icon"><?php echo AccountRewardsIcon::get( 'empty' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed SVG. ?></span>
        <p>
            <?php echo esc_html( LauncherCopy::no_activity() ); ?>
            <?php
            /* translators: %s: the store's points label, as "Points". */
            echo esc_html( sprintf( __( 'Make your first purchase to start earning %s.', 'yayloyalty' ), $yayloyalty_label ) );
            ?>
        </p>
    </div>
    <p class="yayloyalty-account__hint" data-yl-activity-error hidden><?php esc_html_e( 'Your recent activity could not be loaded. Try again later.', 'yayloyalty' ); ?></p>
</section>
