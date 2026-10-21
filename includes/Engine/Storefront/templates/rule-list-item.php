<?php
/**
 * One row of "Ways to earn" or "Ways to redeem": icon, name (linked when it has a url), the line under it, and the value.
 * Styled by assets/storefront/account/rule-list-item.css.
 *
 * @var array{icon: string, name: string, url?: string, text?: string, value: string, tone?: string} $yayloyalty_item
 *      `icon` an AccountRewardsIcon SVG; `url` and `text` missing or '' for none; `tone` 'positive' to colour the value.
 */

use YayLoyalty\Engine\Storefront\AccountRewardsIcon;

defined( 'ABSPATH' ) || exit;

$yayloyalty_item     += [
    'url'  => '',
    'text' => '',
];
$yayloyalty_item_tone = 'positive' === ( $yayloyalty_item['tone'] ?? '' ) ? ' yayloyalty-account__item-value--positive' : '';
?>
<li class="yayloyalty-account__item">
    <span class="yayloyalty-account__item-icon"><?php echo $yayloyalty_item['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed SVG. ?></span>
    <span class="yayloyalty-account__item-body">
        <?php if ( '' !== $yayloyalty_item['url'] ) : ?>
            <a class="yayloyalty-account__item-name" href="<?php echo esc_url( $yayloyalty_item['url'] ); ?>"><?php echo esc_html( $yayloyalty_item['name'] ); ?><?php echo AccountRewardsIcon::get( 'external' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed SVG. ?></a>
        <?php else : ?>
            <span class="yayloyalty-account__item-name"><?php echo esc_html( $yayloyalty_item['name'] ); ?></span>
        <?php endif; ?>
        <?php if ( '' !== $yayloyalty_item['text'] ) : ?>
            <span class="yayloyalty-account__item-text"><?php echo esc_html( $yayloyalty_item['text'] ); ?></span>
        <?php endif; ?>
    </span>
    <strong class="yayloyalty-account__item-value<?php echo esc_attr( $yayloyalty_item_tone ); ?>"><?php echo esc_html( $yayloyalty_item['value'] ); ?></strong>
</li>
