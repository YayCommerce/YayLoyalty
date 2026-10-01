<?php
/**
 * Launcher shell: the floating button (1a), the overlay and the panel frame (1b, 1c; a bottom sheet on mobile, 1e).
 *
 * @var array $view Launcher::build_view().
 */

use YayLoyalty\Engine\Storefront\LauncherCopy;
use YayLoyalty\Engine\Storefront\LauncherIcon;
use YayLoyalty\Engine\Storefront\UiClasses;

defined( 'ABSPATH' ) || exit;

$yayloyalty_launcher = $view['launcher'];
$yayloyalty_text     = $view['text'];
$yayloyalty_member   = $view['member'];
$yayloyalty_style    = $yayloyalty_launcher['button_style'];
?>
<div
    class="yayloyalty-launcher"
    data-position="<?php echo esc_attr( $yayloyalty_launcher['position'] ); ?>"
    data-hide-mobile="<?php echo $view['hide_mobile'] ? '1' : '0'; ?>"
    <?php if ( null !== $yayloyalty_member ) : ?>
        data-activity-url="<?php echo esc_url( $view['activity_url'] ); ?>"
        data-nonce="<?php echo esc_attr( $view['nonce'] ); ?>"
    <?php endif; ?>
    style="<?php echo esc_attr( $view['style'] ); ?>"
>
    <button
        type="button"
        class="yl-launcher-trigger <?php echo esc_attr( UiClasses::button( 'primary', 'none' ) ); ?> yl:h-11 yl:gap-2 yl:px-4 yl:shadow-lg yl:text-sm"
        aria-expanded="false"
        aria-controls="yayloyalty-launcher-panel"
        data-yl-open
    >
        <?php if ( 'label' !== $yayloyalty_style ) : ?>
            <?php echo LauncherIcon::html( $yayloyalty_launcher['icon'], (int) $yayloyalty_launcher['icon_upload_id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from fixed SVG strings and esc_url(). ?>
        <?php endif; ?>
        <span class="yl-launcher-label<?php echo 'icon' === $yayloyalty_style ? ' yl:sr-only' : ''; ?>"><?php echo esc_html( $yayloyalty_text['launcher_label'] ); ?></span>
        <?php if ( null !== $yayloyalty_member ) : ?>
            <span class="yl:inline-flex yl:items-center yl:rounded-full yl:bg-background yl:text-foreground yl:h-6 yl:px-2 yl:text-xs yl:font-semibold yl:tabular-nums">
                <?php echo esc_html( LauncherCopy::points( $yayloyalty_member['balance'] ) ); ?>
                <span class="yl:sr-only"><?php esc_html_e( 'points', 'yayloyalty' ); ?></span>
            </span>
        <?php endif; ?>
    </button>

    <div class="yl-launcher-overlay" data-yl-close hidden></div>

    <div
        id="yayloyalty-launcher-panel"
        class="yl-launcher-panel yl:bg-background yl:text-foreground yl:border yl:border-border yl:shadow-lg yl:flex yl:flex-col yl:text-sm"
        role="dialog"
        tabindex="-1"
        aria-modal="true"
        aria-labelledby="yayloyalty-launcher-title"
        data-state="closed"
        hidden
    >
        <div class="yl-launcher-handle" data-yl-handle aria-hidden="true"><span class="yl:block yl:h-1 yl:w-10 yl:rounded-full yl:bg-border"></span></div>

        <div class="yl:flex yl:items-center yl:justify-between yl:gap-3 yl:border-b yl:border-divider yl:px-5 yl:py-4">
            <h2 id="yayloyalty-launcher-title" class="<?php echo esc_attr( UiClasses::TITLE ); ?>"><?php echo esc_html( $yayloyalty_text['launcher_label'] ); ?></h2>
            <button type="button" class="<?php echo esc_attr( UiClasses::button( 'ghost', 'icon-sm' ) ); ?> yl:text-muted-foreground yl:hover:text-foreground" data-yl-close aria-label="<?php esc_attr_e( 'Close', 'yayloyalty' ); ?>">
                <?php echo LauncherIcon::close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG string. ?>
            </button>
        </div>

        <div class="yl-launcher-body yl:flex yl:flex-col yl:gap-5 yl:overflow-y-auto yl:px-5 yl:py-5">
            <?php
            if ( null !== $yayloyalty_member ) {
                include __DIR__ . '/panel-member.php';
            } else {
                include __DIR__ . '/panel-guest.php';
            }
            ?>
        </div>

        <?php if ( null !== $yayloyalty_member ) : ?>
            <div class="yl:flex yl:items-center yl:gap-3 yl:border-t yl:border-divider yl:px-5 yl:py-3">
                <a class="<?php echo esc_attr( UiClasses::button( 'link', 'none' ) ); ?> yl:text-sm" href="<?php echo esc_url( $view['account_url'] ); ?>">
                    <?php esc_html_e( 'View all rewards activity', 'yayloyalty' ); ?>
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>
