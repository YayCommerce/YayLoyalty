<?php
/**
 * Launcher shell: the floating button, the overlay and the panel frame (a bottom sheet on mobile).
 *
 * @var array $view Launcher::build_view().
 */

use YayLoyalty\Engine\Storefront\LauncherCopy;
use YayLoyalty\Engine\Storefront\LauncherIcon;
use YayLoyalty\Engine\Storefront\UiClasses;
use YayLoyalty\Helpers\SettingsHelper;

defined( 'ABSPATH' ) || exit;

$yayloyalty_launcher = $view['launcher'];
$yayloyalty_text     = $view['text'];
$yayloyalty_member   = $view['member'];
$yayloyalty_style    = $yayloyalty_launcher['button_style'];
// Drawn open when the page runs no script to open it (the Design tab's preview).
$yayloyalty_is_open = ! empty( $view['is_open'] );
?>
<div
    class="yayloyalty-launcher"
    data-position="<?php echo esc_attr( $yayloyalty_launcher['position'] ); ?>"
    data-hide-mobile="<?php echo $view['hide_mobile'] ? '1' : '0'; ?>"
    <?php if ( null !== $yayloyalty_member ) : ?>
        data-activity-url="<?php echo esc_url( $view['activity_url'] ?? '' ); ?>"
        data-nonce="<?php echo esc_attr( $view['nonce'] ?? '' ); ?>"
    <?php endif; ?>
    style="<?php echo esc_attr( $view['style'] ); ?>"
>
    <button
        type="button"
        class="yl-launcher-trigger yl:inline-flex yl:items-center yl:gap-2.5 yl:whitespace-nowrap yl:h-11 yl:rounded-lg yl:px-[18px] yl:bg-primary yl:text-primary-foreground yl:hover:bg-primary-accent yl:shadow-lg yl:text-sm yl:font-medium yl:cursor-pointer yl:transition-colors yl:outline-none yl:focus-visible:ring-[1.5px] yl:focus-visible:ring-ring yl:focus-visible:ring-offset-2"
        aria-expanded="<?php echo $yayloyalty_is_open ? 'true' : 'false'; ?>"
        aria-controls="yayloyalty-launcher-panel"
        data-yl-open
    >
        <?php if ( 'label' !== $yayloyalty_style ) : ?>
            <?php echo LauncherIcon::html( $yayloyalty_launcher['icon'], (int) $yayloyalty_launcher['icon_upload_id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from fixed SVG strings and esc_url(). ?>
        <?php endif; ?>
        <span class="yl-launcher-label<?php echo 'icon' === $yayloyalty_style ? ' yl:sr-only' : ''; ?>"><?php echo esc_html( $yayloyalty_text['launcher_label'] ); ?></span>
        <?php if ( null !== $yayloyalty_member ) : ?>
            <span class="yl-launcher-badge yl:inline-flex yl:items-center yl:justify-center yl:rounded-full yl:bg-primary-foreground yl:text-primary yl:h-[22px] yl:min-w-[52px] yl:px-2 yl:text-xs yl:font-semibold yl:tabular-nums">
                <?php echo esc_html( LauncherCopy::points( $yayloyalty_member['balance'] ) ); ?>
                <span class="yl:sr-only"><?php echo esc_html( SettingsHelper::points_label( $yayloyalty_member['balance'] ) ); ?></span>
            </span>
        <?php endif; ?>
    </button>

    <div class="yl-launcher-overlay" data-yl-close data-state="<?php echo $yayloyalty_is_open ? 'open' : 'closed'; ?>" <?php echo $yayloyalty_is_open ? '' : 'hidden'; ?>></div>

    <div
        id="yayloyalty-launcher-panel"
        class="yl-launcher-panel yl:bg-background yl:text-foreground yl:border yl:border-border yl:shadow-2xl yl:flex yl:flex-col yl:text-sm"
        role="dialog"
        tabindex="-1"
        aria-modal="true"
        aria-labelledby="yayloyalty-launcher-title"
        data-state="<?php echo $yayloyalty_is_open ? 'open' : 'closed'; ?>"
        <?php echo $yayloyalty_is_open ? '' : 'hidden'; ?>
    >
        <div class="yl-launcher-handle" data-yl-handle aria-hidden="true"><span class="yl:block yl:h-1 yl:w-11 yl:rounded-full yl:bg-border"></span></div>

        <div class="yl-launcher-header yl:flex yl:items-center yl:gap-2.5 yl:bg-primary yl:text-primary-foreground yl:px-4 yl:py-2.5">
            <?php if ( 'label' !== $yayloyalty_style ) : ?>
                <span class="yl-launcher-header-icon yl:inline-flex yl:[&>*]:size-4"><?php echo LauncherIcon::html( $yayloyalty_launcher['icon'], (int) $yayloyalty_launcher['icon_upload_id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from fixed SVG strings and esc_url(). ?></span>
            <?php endif; ?>
            <h2 id="yayloyalty-launcher-title" class="yl:m-0 yl:text-sm yl:font-semibold yl:leading-tight yl:text-primary-foreground"><?php echo esc_html( $yayloyalty_text['launcher_label'] ); ?></h2>
            <button type="button" class="yl-launcher-close yl:ml-auto yl:inline-flex yl:size-7 yl:items-center yl:justify-center yl:rounded-md yl:text-primary-foreground yl:hover:bg-primary-foreground/15 yl:cursor-pointer yl:transition-colors yl:outline-none yl:focus-visible:ring-[1.5px] yl:focus-visible:ring-primary-foreground" data-yl-close aria-label="<?php esc_attr_e( 'Close', 'yayloyalty' ); ?>">
                <?php echo LauncherIcon::close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG string. ?>
            </button>
        </div>

        <div class="yl-launcher-body yl:flex yl:flex-col yl:overflow-y-auto">
            <?php
            if ( null !== $yayloyalty_member ) {
                include __DIR__ . '/panel-member.php';
            } else {
                include __DIR__ . '/panel-guest.php';
            }
            ?>
        </div>

        <?php if ( null !== $yayloyalty_member ) : ?>
            <div class="yl:flex yl:items-center yl:gap-2.5 yl:border-t yl:border-border yl:bg-muted/50 yl:px-4 yl:py-3">
                <a class="yl:text-xs yl:text-primary yl:hover:underline yl:underline-offset-4" href="<?php echo esc_url( $view['account_url'] ); ?>">
                    <?php esc_html_e( 'View all rewards activity', 'yayloyalty' ); ?>
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>
