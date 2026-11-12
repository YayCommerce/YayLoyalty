<?php
/**
 * "Missing points from a guest order?": the My Points tab's form that links a guest order placed with the account's
 * email. Posts back to the tab, where AccountClaimOrder::maybe_claim() handles it and WooCommerce prints the notice.
 * Styled by assets/storefront/account/claim-order.css; the button is points-card.css's.
 */

use YayLoyalty\Engine\Storefront\AccountClaimOrder;
use YayLoyalty\Engine\Storefront\AccountMyPoints;

defined( 'ABSPATH' ) || exit;

$yayloyalty_claim_field = 'yayloyalty-claim-order-number';
?>
<section class="yayloyalty-account__section yayloyalty-account__claim">
    <h3 class="yayloyalty-account__title"><?php esc_html_e( 'Missing points from a guest order?', 'yayloyalty' ); ?></h3>
    <p class="yayloyalty-account__hint">
        <?php
        /* translators: %s: the account's email address */
        echo esc_html( sprintf( __( 'Enter the order number from your confirmation email. The order must have been placed with %s.', 'yayloyalty' ), wp_get_current_user()->user_email ) );
        ?>
    </p>
    <form class="yayloyalty-account__claim-form" method="post" action="<?php echo esc_url( AccountMyPoints::url() ); ?>">
        <label class="screen-reader-text" for="<?php echo esc_attr( $yayloyalty_claim_field ); ?>"><?php esc_html_e( 'Order number', 'yayloyalty' ); ?></label>
        <input
            id="<?php echo esc_attr( $yayloyalty_claim_field ); ?>"
            class="yayloyalty-account__claim-input"
            type="text"
            name="<?php echo esc_attr( AccountClaimOrder::FIELD ); ?>"
            placeholder="<?php esc_attr_e( 'Order number', 'yayloyalty' ); ?>"
            inputmode="numeric"
            autocomplete="off"
            required
        />
        <?php wp_nonce_field( AccountClaimOrder::NONCE_ACTION ); ?>
        <button type="submit" class="yayloyalty-account__button"><?php esc_html_e( 'Claim points', 'yayloyalty' ); ?></button>
    </form>
</section>
