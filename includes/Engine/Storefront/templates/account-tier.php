<?php
/**
 * My Points' "Your tier" section, under the points card: a status card (badge, multiplier, progress to the next
 * tier with a bar, the qualifying window), the member's tier details, then every tier with its threshold and perks.
 * Prints nothing while `$yayloyalty_tier` is null (AccountMyPoints::MEMBER_TIER_FILTER has no answer). The progress
 * sentences are LauncherCopy's, shared with the launcher's tier-progress.php.
 * Styled by assets/storefront/account/account-tier.css.
 *
 * @var array|null $yayloyalty_tier AccountMyPoints::view()['tier'].
 */

use YayLoyalty\Engine\Storefront\LauncherCopy;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

if ( null === $yayloyalty_tier ) {
    return;
}

$yayloyalty_tier_next = $yayloyalty_tier['next'];
$yayloyalty_tier_text = LauncherCopy::tier_progress_text( $yayloyalty_tier_next, $yayloyalty_tier['needed'] );
?>
<section class="yayloyalty-account__section yayloyalty-account__tiers">
    <h3 class="yayloyalty-account__title"><?php esc_html_e( 'Your tier', 'yayloyalty' ); ?></h3>
    <div class="yayloyalty-account__tiers-card">
        <div class="yayloyalty-account__tiers-top">
            <?php if ( null !== $yayloyalty_tier['name'] ) : ?>
                <span class="yayloyalty-account__tiers-badge">
                    <span class="yayloyalty-account__tiers-dot" style="background-color: <?php echo esc_attr( (string) $yayloyalty_tier['color'] ); ?>" aria-hidden="true"></span>
                    <?php echo esc_html( $yayloyalty_tier['name'] ); ?>
                </span>
                <?php if ( null !== $yayloyalty_tier['multiplier'] && (float) $yayloyalty_tier['multiplier'] > 1 ) : ?>
                    <span class="yayloyalty-account__tiers-chip"><?php echo esc_html( LauncherCopy::tier_multiplier( $yayloyalty_tier['multiplier'] ) ); ?></span>
                <?php endif; ?>
            <?php else : ?>
                <span class="yayloyalty-account__tiers-badge"><?php esc_html_e( 'No tier yet', 'yayloyalty' ); ?></span>
            <?php endif; ?>
        </div>
        <p class="yayloyalty-account__tiers-text"><?php echo esc_html( $yayloyalty_tier_text ); ?></p>
        <?php
        if ( null !== $yayloyalty_tier_next ) :
            $yayloyalty_tier_threshold = $yayloyalty_tier_next['threshold'];
            ?>
            <div class="yayloyalty-account__tiers-bar" role="progressbar" aria-valuemin="0" aria-valuemax="<?php echo esc_attr( (string) $yayloyalty_tier_threshold ); ?>" aria-valuenow="<?php echo esc_attr( (string) min( $yayloyalty_tier['progress'], $yayloyalty_tier_threshold ) ); ?>" aria-label="<?php echo esc_attr( $yayloyalty_tier_text ); ?>">
                <span class="yayloyalty-account__tiers-fill" style="width: <?php echo esc_attr( (string) LauncherCopy::tier_progress_percent( $yayloyalty_tier['progress'], $yayloyalty_tier_threshold ) ); ?>%"></span>
            </div>
        <?php endif; ?>
        <?php if ( null !== $yayloyalty_tier['window_months'] ) : ?>
            <p class="yayloyalty-account__tiers-caption"><?php echo esc_html( LauncherCopy::tier_window_caption( $yayloyalty_tier['window_months'] ) ); ?></p>
        <?php endif; ?>
        <dl class="yayloyalty-account__tiers-details">
            <?php if ( null !== $yayloyalty_tier['achieved_at'] ) : ?>
                <div class="yayloyalty-account__tiers-detail">
                    <dt><?php esc_html_e( 'Tier since', 'yayloyalty' ); ?></dt>
                    <dd><?php echo esc_html( Dates::to_site_date( $yayloyalty_tier['achieved_at'] ) ); ?></dd>
                </div>
            <?php endif; ?>
            <div class="yayloyalty-account__tiers-detail">
                <?php /* translators: %s: the store's points label, as "Points". */ ?>
                <dt><?php echo esc_html( sprintf( __( 'Tier %s', 'yayloyalty' ), SettingsHelper::points_label_plural() ) ); ?></dt>
                <dd><?php echo esc_html( LauncherCopy::points( $yayloyalty_tier['progress'] ) ); ?></dd>
            </div>
            <?php if ( null !== $yayloyalty_tier['held_until'] ) : ?>
                <div class="yayloyalty-account__tiers-detail">
                    <dt><?php esc_html_e( 'Kept until', 'yayloyalty' ); ?></dt>
                    <dd><?php echo esc_html( Dates::to_site_date( $yayloyalty_tier['held_until'] ) ); ?></dd>
                </div>
            <?php endif; ?>
        </dl>
    </div>
    <h4 class="yayloyalty-account__tiers-subtitle"><?php esc_html_e( 'All tiers', 'yayloyalty' ); ?></h4>
    <ul class="yayloyalty-account__tiers-list">
        <?php
        foreach ( $yayloyalty_tier['tiers'] as $yayloyalty_tier_row ) :
            $yayloyalty_tier_perks = [];

            if ( (float) $yayloyalty_tier_row['multiplier'] > 1 ) {
                $yayloyalty_tier_perks[] = LauncherCopy::tier_multiplier( $yayloyalty_tier_row['multiplier'] );
            }

            if ( $yayloyalty_tier_row['entry_bonus'] > 0 ) {
                /* translators: %s: points with the store's label, as "200 Points". */
                $yayloyalty_tier_perks[] = sprintf( __( '+%s bonus', 'yayloyalty' ), SettingsHelper::points_text( $yayloyalty_tier_row['entry_bonus'] ) );
            }
            ?>
            <li class="yayloyalty-account__tiers-row<?php echo $yayloyalty_tier_row['is_current'] ? ' is-current' : ''; ?>"<?php echo $yayloyalty_tier_row['is_current'] ? ' aria-current="true"' : ''; ?>>
                <span class="yayloyalty-account__tiers-row-name">
                    <span class="yayloyalty-account__tiers-dot" style="background-color: <?php echo esc_attr( $yayloyalty_tier_row['color'] ); ?>" aria-hidden="true"></span>
                    <?php echo esc_html( $yayloyalty_tier_row['name'] ); ?>
                    <?php if ( $yayloyalty_tier_row['is_current'] ) : ?>
                        <span class="yayloyalty-account__tiers-chip"><?php esc_html_e( 'You', 'yayloyalty' ); ?></span>
                    <?php endif; ?>
                </span>
                <span class="yayloyalty-account__tiers-row-meta">
                    <?php
                    /* translators: %s: points with the store's label, as "1,000 Points". */
                    echo esc_html( sprintf( __( 'From %s', 'yayloyalty' ), SettingsHelper::points_text( $yayloyalty_tier_row['threshold'] ) ) );
                    ?>
                </span>
                <?php if ( [] !== $yayloyalty_tier_perks ) : ?>
                    <span class="yayloyalty-account__tiers-row-perks"><?php echo esc_html( implode( ' · ', $yayloyalty_tier_perks ) ); ?></span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
