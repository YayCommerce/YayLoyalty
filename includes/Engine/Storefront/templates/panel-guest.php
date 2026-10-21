<?php
/**
 * Guest panel body: the top card, "How it works" in the Rewards tab's rows, then join and sign in.
 * Styled by assets/storefront/account/panel-guest.css.
 *
 * Rates are stated plainly (they are the argument), at the base rate only: a tier multiplier
 * belongs to members, and promising it before signup would be untrue.
 *
 * @var array $view            Launcher::view().
 * @var array $yayloyalty_text The Design tab's storefront_text.
 */

use YayLoyalty\Engine\Storefront\AccountRewardsIcon;
use YayLoyalty\Engine\Storefront\LauncherCopy;
use YayLoyalty\Engine\Storefront\LauncherEarnRules;
use YayLoyalty\Engine\Storefront\LauncherPayload;
use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Helpers\SettingsHelper;

defined( 'ABSPATH' ) || exit;

$yayloyalty_earn             = $view['earn'];
$yayloyalty_route            = $view['register'];
$yayloyalty_signup           = $yayloyalty_earn[ CampaignTrigger::SIGNUP ];
$yayloyalty_is_checkout_only = 'checkout' === $yayloyalty_route;
$yayloyalty_steps            = array_map( [ LauncherEarnRules::class, 'list_item' ], LauncherEarnRules::guest_rows( $view ) );

if ( null !== $view['redeem'] ) {
    $yayloyalty_steps[] = [
        'icon'  => AccountRewardsIcon::get( 'ticket' ),
        'name'  => __( 'Redeem & save', 'yayloyalty' ),
        'value' => LauncherCopy::redeem_rate( $view['redeem']['points_per_unit'] ),
    ];
}

if ( null === $yayloyalty_signup ) {
    $yayloyalty_register_text = LauncherCopy::create_account( $yayloyalty_route );
} elseif ( $yayloyalty_is_checkout_only ) {
    /* translators: %s: points with the store's label, as "100 Points". */
    $yayloyalty_register_text = sprintf( __( 'Join at checkout & get %s', 'yayloyalty' ), SettingsHelper::points_text( $yayloyalty_signup ) );
} else {
    /* translators: %s: points with the store's label, as "100 Points". */
    $yayloyalty_register_text = sprintf( __( 'Join & get %s', 'yayloyalty' ), SettingsHelper::points_text( $yayloyalty_signup ) );
}

require __DIR__ . '/guest-card.php';
?>
<?php if ( [] !== $yayloyalty_steps ) : ?>
    <section class="yayloyalty-account__section yayloyalty-account__steps">
        <h3 class="yayloyalty-account__title"><?php esc_html_e( 'How it works', 'yayloyalty' ); ?></h3>
        <ul class="yayloyalty-account__list">
            <?php
            foreach ( $yayloyalty_steps as $yayloyalty_item ) {
                require __DIR__ . '/rule-list-item.php';
            }
            ?>
        </ul>
    </section>
<?php endif; ?>

<div class="yayloyalty-account__actions">
    <?php if ( null !== $yayloyalty_route ) : ?>
        <a class="yayloyalty-account__button" href="<?php echo esc_url( LauncherPayload::register_url( $yayloyalty_route ) ); ?>"><?php echo esc_html( $yayloyalty_register_text ); ?></a>
    <?php endif; ?>
    <a class="yayloyalty-account__button<?php echo null !== $yayloyalty_route ? ' yayloyalty-account__button--secondary' : ''; ?>" href="<?php echo esc_url( $view['account_url'] ); ?>"><?php esc_html_e( 'Sign in', 'yayloyalty' ); ?></a>
</div>
