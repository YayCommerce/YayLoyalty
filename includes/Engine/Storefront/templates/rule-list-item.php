<?php
/**
 * One row of "Ways to earn" or "Ways to redeem": icon, name (linked when it has a url), the line under it, and the value.
 * Styled by assets/storefront/account/rule-list-item.css.
 *
 * @var array{icon: string, name: string, url?: string, text?: string, value: string, tone?: string, bonuses?: string[]} $yayloyalty_item
 *      `icon` an AccountMyPointsIcon SVG; `url` and `text` missing or '' for none; `tone` 'positive' to colour the value,
 *      'locked' for a reward the viewer can't claim yet (its `text` the teaser);
 *      `bonuses` the lines teasing what conditional campaigns pay more, under the text.
 */

use YayLoyalty\Engine\Storefront\AccountMyPointsIcon;
use YayLoyalty\Utils\Kses;

defined( 'ABSPATH' ) || exit;

$yayloyalty_item       += [
    'url'     => '',
    'text'    => '',
    'bonuses' => [],
];
$yayloyalty_item_tone   = 'positive' === ( $yayloyalty_item['tone'] ?? '' ) ? ' yayloyalty-account__item-value--positive' : '';
$yayloyalty_item_locked = 'locked' === ( $yayloyalty_item['tone'] ?? '' ) ? ' yayloyalty-rule--locked' : '';
?>
<li class="yayloyalty-account__item<?php echo esc_attr( $yayloyalty_item_locked ); ?>">
    <span class="yayloyalty-account__item-icon"><?php echo wp_kses( $yayloyalty_item['icon'], Kses::svg() ); ?></span>
    <span class="yayloyalty-account__item-body">
        <?php if ( '' !== $yayloyalty_item['url'] ) : ?>
            <a class="yayloyalty-account__item-name" href="<?php echo esc_url( $yayloyalty_item['url'] ); ?>"><?php echo esc_html( $yayloyalty_item['name'] ); ?><?php echo wp_kses( AccountMyPointsIcon::get( 'external' ), Kses::svg() ); ?></a>
        <?php else : ?>
            <span class="yayloyalty-account__item-name"><?php echo esc_html( $yayloyalty_item['name'] ); ?></span>
        <?php endif; ?>
        <?php if ( '' !== $yayloyalty_item['text'] ) : ?>
            <span class="yayloyalty-account__item-text"><?php echo esc_html( $yayloyalty_item['text'] ); ?></span>
        <?php endif; ?>
        <?php foreach ( $yayloyalty_item['bonuses'] as $yayloyalty_item_bonus ) : ?>
            <span class="yayloyalty-account__item-bonus"><?php echo esc_html( $yayloyalty_item_bonus ); ?></span>
        <?php endforeach; ?>
    </span>
    <strong class="yayloyalty-account__item-value<?php echo esc_attr( $yayloyalty_item_tone ); ?>"><?php echo esc_html( $yayloyalty_item['value'] ); ?></strong>
</li>
