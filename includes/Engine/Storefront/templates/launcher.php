<?php
/**
 * Launcher shell: the floating button, the overlay and the panel frame (a bottom sheet on mobile).
 * The button is a round gift icon with the member's balance in a pill under it, and a dot while their
 * points expire within LauncherPayload::EXPIRING_SOON_DAYS. Its geometry is in apps/admin/src/styles/storefront-launcher-layout.css.
 *
 * @var array $view Launcher::view().
 */

use YayLoyalty\Engine\Storefront\LauncherCopy;
use YayLoyalty\Engine\Storefront\LauncherIcon;
use YayLoyalty\Helpers\SettingsHelper;

defined( 'ABSPATH' ) || exit;

$yayloyalty_launcher = $view['launcher'];
$yayloyalty_member   = $view['member'];
// Drawn open when the page runs no script to open it (the Design tab's preview).
$yayloyalty_is_open = ! empty( $view['is_open'] );
?>
<div
    class="yayloyalty-launcher"
    data-position="<?php echo esc_attr( $yayloyalty_launcher['position'] ); ?>"
    data-hide-mobile="<?php echo $view['hide_mobile'] ? '1' : '0'; ?>"
    <?php if ( null !== $yayloyalty_member ) : ?>
        data-activity-url="<?php echo esc_url( $view['activity_url'] ?? '' ); ?>"
        data-seen-url="<?php echo esc_url( $view['seen_url'] ?? '' ); ?>"
        data-nonce="<?php echo esc_attr( $view['nonce'] ?? '' ); ?>"
    <?php endif; ?>
    style="<?php echo esc_attr( $view['style'] ); ?>"
>
    <button
        type="button"
        class="yl-launcher-trigger yl:inline-flex yl:items-center yl:justify-center yl:rounded-full yl:bg-primary yl:text-primary-foreground yl:hover:bg-primary-accent yl:shadow-lg yl:cursor-pointer yl:outline-none yl:focus-visible:ring-[1.5px] yl:focus-visible:ring-ring yl:focus-visible:ring-offset-2"
        aria-expanded="<?php echo $yayloyalty_is_open ? 'true' : 'false'; ?>"
        aria-controls="yayloyalty-launcher-panel"
        data-yl-open
    >
        <span class="yl-launcher-icon" aria-hidden="true"><?php echo LauncherIcon::gift(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG string. ?></span>
        <?php // The label is not drawn, only read: it stays the button's accessible name. ?>
        <span class="yl:sr-only"><?php echo esc_html( LauncherCopy::launcher_label() ); ?></span>
        <?php if ( null !== $yayloyalty_member ) : ?>
            <?php // The dot says points expire within LauncherPayload::EXPIRING_SOON_DAYS; the Rewards tab spells it out. ?>
            <?php if ( null !== $yayloyalty_member['expires_at'] ) : ?>
                <span class="yl-launcher-dot" aria-hidden="true"></span>
            <?php endif; ?>
            <span class="yl-launcher-balance yl:bg-background yl:text-primary yl:font-semibold yl:tabular-nums">
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

        <div class="yl-launcher-header yl:flex yl:items-center yl:gap-2.5 yl:bg-foreground yl:text-background yl:px-5 yl:py-[11px]">
            <span class="yl-launcher-header-icon yl:inline-flex yl:[&>*]:size-4"><?php echo LauncherIcon::gift(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG string. ?></span>
            <h2 id="yayloyalty-launcher-title" class="yl:m-0 yl:text-[15px] yl:font-semibold yl:leading-tight yl:text-background"><?php echo esc_html( LauncherCopy::launcher_label() ); ?></h2>
            <button type="button" class="yl-launcher-close yl:ml-auto yl:inline-flex yl:size-7 yl:items-center yl:justify-center yl:rounded-md yl:text-background yl:hover:bg-background/15 yl:cursor-pointer yl:transition-colors yl:outline-none yl:focus-visible:ring-[1.5px] yl:focus-visible:ring-background" data-yl-close aria-label="<?php esc_attr_e( 'Close', 'yayloyalty' ); ?>">
                <?php echo LauncherIcon::close(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG string. ?>
            </button>
        </div>

        <?php // .yayloyalty-account: the body prints the Rewards tab's partials, styled by assets/storefront/account/. ?>
        <div class="yl-launcher-body yayloyalty-account yl:flex yl:flex-col yl:overflow-y-auto">
            <?php
            if ( null !== $yayloyalty_member ) {
                include __DIR__ . '/panel-member.php';
            } else {
                include __DIR__ . '/panel-guest.php';
            }
            ?>
        </div>

        <div class="yl-launcher-footer yl:h-[41px] yl:shrink-0 yl:border-t yl:border-border yl:bg-muted/50" aria-hidden="true"></div>
    </div>
</div>
