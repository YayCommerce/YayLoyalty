<?php
/**
 * The member's tier card body, shared by My Points' "Your tier" section (account-tier.php) and the launcher panel
 * (tier-progress.php): the crown, name, date reached and hold, then the tier points box with progress to the next
 * tier.
 * Styled by assets/storefront/account/account-tier.css.
 *
 * @var array $yayloyalty_tier AccountMyPoints::view()['tier'] or LauncherPayload's `member.tier`.
 */

use YayLoyalty\Engine\Storefront\AccountMyPointsIcon;
use YayLoyalty\Engine\Storefront\LauncherCopy;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Kses;

defined( 'ABSPATH' ) || exit;

$yayloyalty_tier_next = $yayloyalty_tier['next'];
?>
<div class="yayloyalty-account__tiers-head">
    <?php echo wp_kses( AccountMyPointsIcon::tier_crown( $yayloyalty_tier['color'], true ), Kses::storefront() ); ?>
    <span class="yayloyalty-account__tiers-identity">
        <span class="yayloyalty-account__tiers-name">
            <?php
            echo null === $yayloyalty_tier['name']
                ? esc_html__( 'No tier yet', 'yayloyalty' )
                /* translators: %s: the tier's name, as "Silver". */
                : esc_html( sprintf( __( '%s tier', 'yayloyalty' ), $yayloyalty_tier['name'] ) );
            ?>
        </span>
        <?php if ( null !== $yayloyalty_tier['achieved_at'] ) : ?>
            <span class="yayloyalty-account__tiers-since"><?php echo esc_html( Dates::to_site_date( $yayloyalty_tier['achieved_at'] ) ); ?></span>
        <?php endif; ?>
    </span>
    <?php if ( null !== $yayloyalty_tier['held_until'] ) : ?>
        <span class="yayloyalty-account__tiers-pill">
            <?php
            /* translators: %s: the date the member's tier is kept until. */
            echo esc_html( sprintf( __( 'Held until %s', 'yayloyalty' ), Dates::to_site_date( $yayloyalty_tier['held_until'] ) ) );
            ?>
        </span>
    <?php endif; ?>
</div>

<div class="yayloyalty-account__tiers-progress">
    <div class="yayloyalty-account__tiers-progress-top">
        <?php /* translators: %s: the store's points label, as "Points". */ ?>
        <span class="yayloyalty-account__tiers-label"><?php echo esc_html( sprintf( __( 'Tier %s', 'yayloyalty' ), SettingsHelper::points_label_plural() ) ); ?></span>
        <span class="yayloyalty-account__tiers-value"><?php echo esc_html( SettingsHelper::points_text( $yayloyalty_tier['progress'] ) ); ?></span>
    </div>
    <?php
    if ( null !== $yayloyalty_tier_next ) :
        $yayloyalty_tier_threshold = $yayloyalty_tier_next['threshold'];
        ?>
        <div class="yayloyalty-account__tiers-bar" role="progressbar" aria-valuemin="0" aria-valuemax="<?php echo esc_attr( (string) $yayloyalty_tier_threshold ); ?>" aria-valuenow="<?php echo esc_attr( (string) min( $yayloyalty_tier['progress'], $yayloyalty_tier_threshold ) ); ?>" aria-label="<?php echo esc_attr( LauncherCopy::tier_progress_text( $yayloyalty_tier_next, $yayloyalty_tier['needed'] ) ); ?>">
            <span class="yayloyalty-account__tiers-fill" style="width: <?php echo esc_attr( (string) LauncherCopy::tier_progress_percent( $yayloyalty_tier['progress'], $yayloyalty_tier_threshold ) ); ?>%"></span>
        </div>
        <div class="yayloyalty-account__tiers-progress-foot">
            <?php /* translators: %s: the next tier's name. */ ?>
            <span class="yayloyalty-account__tiers-next"><?php echo esc_html( sprintf( __( 'Progress to %s', 'yayloyalty' ), $yayloyalty_tier_next['name'] ) ); ?></span>
            <span class="yayloyalty-account__tiers-togo">
                <?php
                echo $yayloyalty_tier['needed'] > 0
                    /* translators: %s: points with the store's label, as "2,200 Points". */
                    ? esc_html( sprintf( __( '%s to go', 'yayloyalty' ), SettingsHelper::points_text( $yayloyalty_tier['needed'] ) ) )
                    : esc_html( LauncherCopy::tier_progress_text( $yayloyalty_tier_next, 0 ) );
                ?>
            </span>
        </div>
    <?php else : ?>
        <p class="yayloyalty-account__tiers-caption"><?php echo esc_html( LauncherCopy::tier_progress_text( null, 0 ) ); ?></p>
    <?php endif; ?>
    <?php if ( null !== $yayloyalty_tier['period'] ) : ?>
        <p class="yayloyalty-account__tiers-caption"><?php echo esc_html( LauncherCopy::tier_period_caption( $yayloyalty_tier['period'] ) ); ?></p>
    <?php elseif ( null !== $yayloyalty_tier['window_months'] ) : ?>
        <p class="yayloyalty-account__tiers-caption"><?php echo esc_html( LauncherCopy::tier_window_caption( $yayloyalty_tier['window_months'] ) ); ?></p>
    <?php endif; ?>
</div>
