<?php
/**
 * My Points' "Your tier" section, under the points card: one card with the member's tier (crown, name, date reached,
 * hold), their tier points and progress to the next tier, then every tier with its requirement, perks and status.
 * Prints nothing while `$yayloyalty_tier` is null (AccountMyPoints::MEMBER_TIER_FILTER has no answer).
 * Styled by assets/storefront/account/account-tier.css.
 *
 * @var array|null $yayloyalty_tier AccountMyPoints::view()['tier'].
 */

use YayLoyalty\Engine\Storefront\AccountMyPointsIcon;
use YayLoyalty\Engine\Storefront\LauncherCopy;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Utils\Kses;

defined( 'ABSPATH' ) || exit;

if ( null === $yayloyalty_tier ) {
    return;
}

$yayloyalty_tier_icons  = [
    'achieved' => 'clock',
    'current'  => 'check',
    'locked'   => 'lock',
];
$yayloyalty_tier_labels = [
    'achieved' => __( 'Achieved', 'yayloyalty' ),
    'current'  => __( 'Current', 'yayloyalty' ),
    'locked'   => __( 'Locked', 'yayloyalty' ),
];

// Tiers arrive in rank order, so every tier before the member's is one they have passed.
$yayloyalty_tier_current = array_search( true, array_column( $yayloyalty_tier['tiers'], 'is_current' ), true );
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

                        if ( false === $yayloyalty_tier_current || $yayloyalty_tier_index > $yayloyalty_tier_current ) {
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
                                    <?php echo wp_kses( AccountMyPointsIcon::tier_crown( $yayloyalty_tier_row['color'] ), Kses::storefront() ); ?>
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
                                    <?php echo wp_kses( AccountMyPointsIcon::get( $yayloyalty_tier_icons[ $yayloyalty_tier_status ] ), Kses::svg() ); ?>
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
