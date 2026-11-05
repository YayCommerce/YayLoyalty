<?php
/**
 * The launcher panel's tier line, under the points card: the member's tier badge, progress to the next tier with
 * a bar and, over a qualifying window, the window and the date the tier is kept until. Prints nothing while the
 * member's `tier` is null (LauncherPayload::MEMBER_TIER_FILTER has no answer). Launcher only: points-card.php is
 * shared with My Account and the placements, which don't show it. Styled by assets/storefront/account/tier-progress.css.
 *
 * @var array $yayloyalty_member LauncherPayload's `member` ($view['member']); `tier` set by LauncherPayload::for_balance().
 */

use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

$yayloyalty_tier = $yayloyalty_member['tier'] ?? null;

if ( null === $yayloyalty_tier ) {
    return;
}

$yayloyalty_tier_next = $yayloyalty_tier['next'];

if ( null === $yayloyalty_tier_next ) {
    $yayloyalty_tier_text = __( 'Top tier', 'yayloyalty' );
} elseif ( $yayloyalty_tier['needed'] > 0 ) {
    /* translators: 1: points with the store's label, as "120 Points", 2: the next tier's name. */
    $yayloyalty_tier_text = sprintf( __( '%1$s to %2$s', 'yayloyalty' ), SettingsHelper::points_text( $yayloyalty_tier['needed'] ), $yayloyalty_tier_next['name'] );
} else {
    /* translators: %s: the next tier's name. */
    $yayloyalty_tier_text = sprintf( __( '%s unlocks with your next earn', 'yayloyalty' ), $yayloyalty_tier_next['name'] );
}
?>
<section class="yayloyalty-account__tier">
    <?php if ( null !== $yayloyalty_tier['name'] ) : ?>
        <span class="yayloyalty-account__tier-badge">
            <span class="yayloyalty-account__tier-dot" style="background-color: <?php echo esc_attr( (string) $yayloyalty_tier['color'] ); ?>" aria-hidden="true"></span>
            <?php echo esc_html( $yayloyalty_tier['name'] ); ?>
        </span>
    <?php endif; ?>
    <p class="yayloyalty-account__tier-text"><?php echo esc_html( $yayloyalty_tier_text ); ?></p>
    <?php
    if ( null !== $yayloyalty_tier_next ) :
        $yayloyalty_tier_threshold = $yayloyalty_tier_next['threshold'];
        // A threshold of 0 is already reached: a full bar, and no division by zero.
        $yayloyalty_tier_pct = 0 === $yayloyalty_tier_threshold ? 100 : min( 100, intdiv( $yayloyalty_tier['progress'] * 100, $yayloyalty_tier_threshold ) );
        ?>
        <div class="yayloyalty-account__tier-bar" role="progressbar" aria-valuemin="0" aria-valuemax="<?php echo esc_attr( (string) $yayloyalty_tier_threshold ); ?>" aria-valuenow="<?php echo esc_attr( (string) min( $yayloyalty_tier['progress'], $yayloyalty_tier_threshold ) ); ?>" aria-label="<?php echo esc_attr( $yayloyalty_tier_text ); ?>">
            <span class="yayloyalty-account__tier-fill" style="width: <?php echo esc_attr( (string) $yayloyalty_tier_pct ); ?>%"></span>
        </div>
    <?php endif; ?>
    <?php
    if ( null !== $yayloyalty_tier['window_months'] ) :
        /* translators: 1: the store's points label, as "Points", 2: number of months. */
        $yayloyalty_tier_caption = sprintf( __( 'Counts %1$s earned in the last %2$d months.', 'yayloyalty' ), SettingsHelper::points_label_plural(), $yayloyalty_tier['window_months'] );

        if ( null !== $yayloyalty_tier['held_until'] ) {
            /* translators: 1: the member's tier name, 2: date. */
            $yayloyalty_tier_caption .= ' ' . sprintf( __( '%1$s kept until %2$s.', 'yayloyalty' ), (string) $yayloyalty_tier['name'], Dates::to_site_date( $yayloyalty_tier['held_until'] ) );
        }
        ?>
        <p class="yayloyalty-account__tier-caption"><?php echo esc_html( $yayloyalty_tier_caption ); ?></p>
    <?php endif; ?>
</section>
