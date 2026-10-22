<?php
/**
 * One "Recent activity" row: empty inside the <template> launcher.js clones and fills, or filled here
 * when the rows are printed server-side. Styled by assets/storefront/account/recent-activity.css.
 *
 * The points sit in the history table's badge (points-history.css), coloured by `tone`; launcher.js adds the tone class.
 *
 * @var array{label: string, meta: string, delta: string, tone: string}|null $yayloyalty_item
 */

defined( 'ABSPATH' ) || exit;
?>
<li class="yayloyalty-account__activity-row">
    <span class="yayloyalty-account__activity-body">
        <span class="yayloyalty-account__activity-label" data-yl-label><?php echo esc_html( $yayloyalty_item['label'] ?? '' ); ?></span>
        <span class="yayloyalty-account__activity-meta" data-yl-meta><?php echo esc_html( $yayloyalty_item['meta'] ?? '' ); ?></span>
    </span>
    <span class="yayloyalty-account__activity-delta yayloyalty-account__badge<?php echo null === $yayloyalty_item ? '' : esc_attr( ' yayloyalty-account__badge--' . $yayloyalty_item['tone'] ); ?>" data-yl-delta><?php echo esc_html( $yayloyalty_item['delta'] ?? '' ); ?></span>
</li>
