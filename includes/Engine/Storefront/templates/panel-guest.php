<?php
/**
 * Guest panel body: the same shell with the balance block replaced by the programme's pitch.
 *
 * Rates are stated plainly (they are the argument), at the base rate only: a tier multiplier
 * belongs to members, and promising it before signup would be untrue.
 *
 * @var array $view    Launcher::build_view().
 * @var array $yayloyalty_text The Design tab's storefront_text.
 */

use YayLoyalty\Engine\Storefront\LauncherCopy;
use YayLoyalty\Engine\Storefront\UiClasses;
use YayLoyalty\Helpers\SettingsHelper;
use YayLoyalty\Enums\CampaignTrigger;

defined( 'ABSPATH' ) || exit;

$yayloyalty_earn             = $view['earn'];
$yayloyalty_redeem           = $view['redeem'];
$yayloyalty_route            = $view['register'];
$yayloyalty_signup           = $yayloyalty_earn[ CampaignTrigger::SIGNUP ];
$yayloyalty_is_checkout_only = 'checkout' === $yayloyalty_route;
$yayloyalty_review           = $yayloyalty_earn[ CampaignTrigger::REVIEW ];
$yayloyalty_steps            = [];

if ( null !== $yayloyalty_signup ) {
    $yayloyalty_steps[] = $yayloyalty_is_checkout_only
        /* translators: %s: points with the store's label, as "50 points". */
        ? sprintf( __( 'Create an account at checkout — %s to start', 'yayloyalty' ), SettingsHelper::points_text( $yayloyalty_signup ) )
        /* translators: %s: points with the store's label, as "50 points". */
        : sprintf( __( 'Create an account — %s to start', 'yayloyalty' ), SettingsHelper::points_text( $yayloyalty_signup ) );
}

if ( null !== $yayloyalty_earn[ CampaignTrigger::PURCHASE ] ) {
    $yayloyalty_rate    = LauncherCopy::earn_rate( $yayloyalty_text['earn_rate_line'], $yayloyalty_earn[ CampaignTrigger::PURCHASE ], $view['purchase_min'] );
    $yayloyalty_steps[] = null === $yayloyalty_review
        ? $yayloyalty_rate
        /* translators: 1: earn-rate sentence, 2: points for a review, with the store's label. */
        : sprintf( __( '%1$s, and %2$s for a review', 'yayloyalty' ), $yayloyalty_rate, SettingsHelper::points_text( $yayloyalty_review ) );
} elseif ( null !== $yayloyalty_review ) {
    /* translators: %s: points with the store's label, as "50 points". */
    $yayloyalty_steps[] = sprintf( __( 'Earn %s for a review', 'yayloyalty' ), SettingsHelper::points_text( $yayloyalty_review ) );
}

if ( null !== $yayloyalty_redeem ) {
    /* translators: %s: "100 points = $1". */
    $yayloyalty_steps[] = sprintf( __( 'Spend them at checkout — %s off', 'yayloyalty' ), LauncherCopy::redeem_rate( $yayloyalty_redeem['points_per_unit'] ) );
}

$yayloyalty_register_url = $yayloyalty_is_checkout_only ? $view['checkout_url'] : $view['account_url'];

if ( null === $yayloyalty_signup ) {
    $yayloyalty_register_text = $yayloyalty_is_checkout_only ? __( 'Create an account at checkout', 'yayloyalty' ) : __( 'Create an account', 'yayloyalty' );
} elseif ( $yayloyalty_is_checkout_only ) {
    /* translators: %s: points with the store's label, as "50 points". */
    $yayloyalty_register_text = sprintf( __( 'Create an account at checkout and get %s', 'yayloyalty' ), SettingsHelper::points_text( $yayloyalty_signup ) );
} else {
    /* translators: %s: points with the store's label, as "50 points". */
    $yayloyalty_register_text = sprintf( __( 'Create an account and get %s', 'yayloyalty' ), SettingsHelper::points_text( $yayloyalty_signup ) );
}
?>
<section class="yl:flex yl:flex-col yl:gap-2.5 yl:border-b yl:border-border yl:p-4">
    <?php if ( '' !== trim( $yayloyalty_text['signed_out_prompt'] ) ) : ?>
        <p class="yl:m-0 yl:text-[17px] yl:font-semibold yl:leading-snug yl:text-foreground"><?php echo esc_html( $yayloyalty_text['signed_out_prompt'] ); ?></p>
    <?php endif; ?>
    <?php if ( null !== $yayloyalty_earn[ CampaignTrigger::PURCHASE ] ) : ?>
        <p class="yl:m-0 yl:text-xs yl:text-muted-foreground"><?php esc_html_e( 'Collect points on every order and spend them on money off your next one. Free to join.', 'yayloyalty' ); ?></p>
    <?php endif; ?>
</section>

<?php if ( $yayloyalty_steps ) : ?>
    <section class="<?php echo esc_attr( UiClasses::PANEL_SECTION ); ?>">
        <h3 class="<?php echo esc_attr( UiClasses::SECTION_HEADING ); ?>"><?php esc_html_e( 'How it works', 'yayloyalty' ); ?></h3>
        <ol class="yl:m-0 yl:flex yl:flex-col yl:list-none yl:p-0">
            <?php foreach ( $yayloyalty_steps as $yayloyalty_index => $yayloyalty_step ) : ?>
                <li class="yl:flex yl:gap-2.5 yl:border-b yl:border-divider yl:py-2.5 yl:text-xs yl:last:border-b-0">
                    <span class="yl:w-4 yl:shrink-0 yl:text-muted-foreground"><?php echo esc_html( number_format_i18n( $yayloyalty_index + 1 ) ); ?></span>
                    <span><?php echo esc_html( $yayloyalty_step ); ?></span>
                </li>
            <?php endforeach; ?>
        </ol>
    </section>
<?php endif; ?>

<section class="yl:flex yl:flex-col yl:gap-2.5 yl:p-4">
    <?php if ( null !== $yayloyalty_route ) : ?>
        <a class="<?php echo esc_attr( UiClasses::button( 'primary' ) ); ?> yl:w-full" href="<?php echo esc_url( $yayloyalty_register_url ); ?>">
            <?php echo esc_html( $yayloyalty_register_text ); ?>
        </a>
    <?php endif; ?>
    <a class="<?php echo esc_attr( UiClasses::button( null !== $yayloyalty_route ? 'primary-outline' : 'primary' ) ); ?> yl:w-full" href="<?php echo esc_url( $view['account_url'] ); ?>">
        <?php esc_html_e( 'Sign in', 'yayloyalty' ); ?>
    </a>
    <?php if ( null !== $view['estimate'] ) : ?>
        <p class="yl:m-0 yl:text-center yl:text-[11px] yl:text-muted-foreground">
            <?php
            /* translators: %s: points with the store's label, as "50 points". */
            echo esc_html( sprintf( __( 'This product would earn you %s', 'yayloyalty' ), SettingsHelper::points_text( $view['estimate'] ) ) );
            ?>
        </p>
    <?php endif; ?>
</section>
