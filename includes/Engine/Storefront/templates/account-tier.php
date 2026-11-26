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

defined( 'ABSPATH' ) || exit;

if ( null === $yayloyalty_tier ) {
    return;
}

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
?>
<section class="yayloyalty-account__section yayloyalty-account__tiers">
    <h3 class="yayloyalty-account__title"><?php esc_html_e( 'Your tier', 'yayloyalty' ); ?></h3>
    <div class="yayloyalty-account__tiers-card">
        <?php require __DIR__ . '/tier-summary.php'; ?>

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
