<?php
/**
 * My Points' "Your tier" section, under the points card: one card with the member's tier (crown, name, date reached,
 * hold), their tier points and progress to the next tier, then every tier with its requirement, perks and status.
 * Prints nothing while `$yayloyalty_tier` is null (AccountMyPoints::MEMBER_TIER_FILTER has no answer).
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

$yayloyalty_tier_next   = $yayloyalty_tier['next'];
$yayloyalty_tier_crown  = '<svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" focusable="false"><path d="M14.334 12.666C15.253 12.666 16.001 13.414 16.001 14.333C16 15.252 15.252 16 14.333 16H1.66699C0.748 16 0 15.252 0 14.333C0 13.414 0.748 12.666 1.66699 12.666H14.334ZM8 0C8.735 0 9.333 0.598 9.333 1.333C9.333 1.858 9.028 2.311 8.587 2.528L11.38 7.526L13.644 5.521C13.45 5.289 13.334 4.991 13.334 4.667C13.334 3.932 13.932 3.334 14.667 3.334C15.402 3.334 16 3.932 16 4.667C16 5.402 15.402 6 14.667 6C14.66 6 14.652 5.998 14.645 5.998L14.126 11.666H1.873L1.354 5.998C1.347 5.998 1.34 6 1.333 6C0.598 6 0 5.402 0 4.667C0 3.932 0.598 3.334 1.333 3.334C2.068 3.334 2.666 3.932 2.666 4.667C2.666 4.992 2.548 5.289 2.354 5.521L4.619 7.526L7.412 2.528C6.972 2.311 6.667 1.858 6.667 1.334C6.667 0.598 7.265 0 8 0ZM8.406 6.872C8.22 6.612 7.78 6.612 7.593 6.872L6.76 8.039C6.633 8.213 6.633 8.453 6.76 8.626L7.593 9.793C7.687 9.92 7.84 10 8 10C8.16 10 8.313 9.92 8.407 9.794L9.24 8.627C9.367 8.453 9.367 8.213 9.239 8.039L8.406 6.872Z"/></svg>';
$yayloyalty_tier_icons  = [
    'achieved' => '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>',
    'current'  => '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M20 6 9 17l-5-5"/></svg>',
    'locked'   => '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
];
$yayloyalty_tier_labels = [
    'achieved' => __( 'Achieved', 'yayloyalty' ),
    'current'  => __( 'Current', 'yayloyalty' ),
    'locked'   => __( 'Locked', 'yayloyalty' ),
];

// Tiers arrive in rank order, so every tier before the member's is one they have passed.
$yayloyalty_tier_current = null;

foreach ( $yayloyalty_tier['tiers'] as $yayloyalty_tier_index => $yayloyalty_tier_row ) {
    if ( $yayloyalty_tier_row['is_current'] ) {
        $yayloyalty_tier_current = $yayloyalty_tier_index;
    }
}

/**
 * The crown on a tint of the tier's colour; muted with no colour.
 *
 * @param string|null $color The tier's hex colour.
 */
$yayloyalty_tier_crown_style = static function ( ?string $color ): string {
    return null === $color ? '' : sprintf( '--yayloyalty-tier-color: %s;', $color );
};
?>
<section class="yayloyalty-account__section yayloyalty-account__tiers">
    <h3 class="yayloyalty-account__title"><?php esc_html_e( 'Your tier', 'yayloyalty' ); ?></h3>
    <div class="yayloyalty-account__tiers-card">
        <div class="yayloyalty-account__tiers-head">
            <span class="yayloyalty-account__tiers-crown is-large" style="<?php echo esc_attr( $yayloyalty_tier_crown_style( $yayloyalty_tier['color'] ) ); ?>">
                <?php echo $yayloyalty_tier_crown; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed SVG literal. ?>
            </span>
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

        <h4 class="yayloyalty-account__tiers-subtitle"><?php esc_html_e( 'All tiers', 'yayloyalty' ); ?></h4>
        <div class="yayloyalty-account__tiers-table-wrap">
            <table class="yayloyalty-account__tiers-table">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e( 'Tier', 'yayloyalty' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Requirement', 'yayloyalty' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Benefits', 'yayloyalty' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Status', 'yayloyalty' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    foreach ( $yayloyalty_tier['tiers'] as $yayloyalty_tier_index => $yayloyalty_tier_row ) :
                        $yayloyalty_tier_perks = [];

                        if ( (float) $yayloyalty_tier_row['multiplier'] > 1 ) {
                            $yayloyalty_tier_perks[] = LauncherCopy::tier_multiplier( $yayloyalty_tier_row['multiplier'] );
                        }

                        if ( $yayloyalty_tier_row['entry_bonus'] > 0 ) {
                            /* translators: %s: points with the store's label, as "200 Points". */
                            $yayloyalty_tier_perks[] = sprintf( __( '%s bonus', 'yayloyalty' ), SettingsHelper::points_text( $yayloyalty_tier_row['entry_bonus'] ) );
                        }

                        if ( null === $yayloyalty_tier_current || $yayloyalty_tier_index > $yayloyalty_tier_current ) {
                            $yayloyalty_tier_status = 'locked';
                        } elseif ( $yayloyalty_tier_index === $yayloyalty_tier_current ) {
                            $yayloyalty_tier_status = 'current';
                        } else {
                            $yayloyalty_tier_status = 'achieved';
                        }
                        ?>
                        <tr class="yayloyalty-account__tiers-row is-<?php echo esc_attr( $yayloyalty_tier_status ); ?>"<?php echo 'current' === $yayloyalty_tier_status ? ' aria-current="true"' : ''; ?>>
                            <td>
                                <span class="yayloyalty-account__tiers-row-name">
                                    <span class="yayloyalty-account__tiers-crown" style="<?php echo esc_attr( $yayloyalty_tier_crown_style( $yayloyalty_tier_row['color'] ) ); ?>">
                                        <?php echo $yayloyalty_tier_crown; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed SVG literal. ?>
                                    </span>
                                    <?php echo esc_html( $yayloyalty_tier_row['name'] ); ?>
                                </span>
                            </td>
                            <td class="yayloyalty-account__tiers-requirement">
                                <?php
                                /* translators: %s: points with the store's label, as "1,000 Points". */
                                echo esc_html( sprintf( __( 'From %s', 'yayloyalty' ), SettingsHelper::points_text( $yayloyalty_tier_row['threshold'] ) ) );
                                ?>
                            </td>
                            <td>
                                <span class="yayloyalty-account__tiers-pill">
                                    <?php echo esc_html( [] === $yayloyalty_tier_perks ? __( 'Standard earning', 'yayloyalty' ) : implode( ' + ', $yayloyalty_tier_perks ) ); ?>
                                </span>
                            </td>
                            <td>
                                <span class="yayloyalty-account__tiers-status is-<?php echo esc_attr( $yayloyalty_tier_status ); ?>">
                                    <?php echo $yayloyalty_tier_icons[ $yayloyalty_tier_status ]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG literals. ?>
                                    <?php echo esc_html( $yayloyalty_tier_labels[ $yayloyalty_tier_status ] ); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
