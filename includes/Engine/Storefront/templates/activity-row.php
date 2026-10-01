<?php
/**
 * One "Recent activity" row: empty inside the <template> launcher.js clones and fills, or filled here
 * when the rows are printed server-side (the Design tab's preview).
 *
 * @var array{label: string, meta: string, delta: string}|null $yayloyalty_item
 */

use YayLoyalty\Engine\Storefront\UiClasses;

defined( 'ABSPATH' ) || exit;
?>
<li class="<?php echo esc_attr( UiClasses::PANEL_ROW ); ?>">
    <span class="yl:flex yl:flex-col yl:gap-0.5 yl:min-w-0">
        <span class="yl:truncate" data-yl-label><?php echo esc_html( $yayloyalty_item['label'] ?? '' ); ?></span>
        <span class="yl:text-muted-foreground yl:text-[10px]" data-yl-meta><?php echo esc_html( $yayloyalty_item['meta'] ?? '' ); ?></span>
    </span>
    <span class="yl:font-semibold yl:tabular-nums" data-yl-delta><?php echo esc_html( $yayloyalty_item['delta'] ?? '' ); ?></span>
</li>
