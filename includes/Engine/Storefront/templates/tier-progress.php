<?php
/**
 * The launcher panel's tier line, under the points card: the member's tier badge, progress to the next tier with
 * a bar and, over a qualifying window, the window and the date the tier is kept until. Prints nothing while the
 * member's `tier` is null (LauncherPayload::MEMBER_TIER_FILTER has no answer). Launcher only: points-card.php is
 * shared with My Account and the placements, which don't show it. Styled by assets/storefront/account/tier-progress.css.
 * Its sentences come from LauncherCopy, which My Points' account-tier.php also reads.
 *
 * @var array $yayloyalty_member LauncherPayload's `member` ($view['member']); `tier` set by LauncherPayload::for_balance().
 */

use YayLoyalty\Engine\Storefront\LauncherCopy;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

$yayloyalty_tier = $yayloyalty_member['tier'] ?? null;

if ( null === $yayloyalty_tier ) {
    return;
}

$yayloyalty_tier_next = $yayloyalty_tier['next'];
$yayloyalty_tier_text = LauncherCopy::tier_progress_text( $yayloyalty_tier_next, $yayloyalty_tier['needed'] );
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
        $yayloyalty_tier_pct       = LauncherCopy::tier_progress_percent( $yayloyalty_tier['progress'], $yayloyalty_tier_threshold );
        ?>
        <div class="yayloyalty-account__tier-bar" role="progressbar" aria-valuemin="0" aria-valuemax="<?php echo esc_attr( (string) $yayloyalty_tier_threshold ); ?>" aria-valuenow="<?php echo esc_attr( (string) min( $yayloyalty_tier['progress'], $yayloyalty_tier_threshold ) ); ?>" aria-label="<?php echo esc_attr( $yayloyalty_tier_text ); ?>">
            <span class="yayloyalty-account__tier-fill" style="width: <?php echo esc_attr( (string) $yayloyalty_tier_pct ); ?>%"></span>
        </div>
    <?php endif; ?>
    <?php
    if ( null !== $yayloyalty_tier['window_months'] ) :
        $yayloyalty_tier_caption = LauncherCopy::tier_window_caption( $yayloyalty_tier['window_months'] );

        if ( null !== $yayloyalty_tier['held_until'] ) {
            /* translators: 1: the member's tier name, 2: date. */
            $yayloyalty_tier_caption .= ' ' . sprintf( __( '%1$s kept until %2$s.', 'yayloyalty' ), (string) $yayloyalty_tier['name'], Dates::to_site_date( $yayloyalty_tier['held_until'] ) );
        }
        ?>
        <p class="yayloyalty-account__tier-caption"><?php echo esc_html( $yayloyalty_tier_caption ); ?></p>
    <?php endif; ?>
</section>
