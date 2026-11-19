<?php
/**
 * My Account → Referrals: the offer, the customer's link with Copy link, four counts and their invitations.
 * Styled by the account stylesheet and includes/Pro/Storefront/assets/account-referrals.css.
 *
 * @var array $view AccountReferrals::view().
 */

use YayLoyalty\Engine\Storefront\LauncherCopy;
use YayLoyalty\Pro\Storefront\AccountReferrals;
use YayLoyalty\Pro\Storefront\ReferralAccountText;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

$yayloyalty_settings = $view['settings'];
$yayloyalty_counts   = $view['counts'];
$yayloyalty_page     = $view['page'];
$yayloyalty_worth    = $view['worth'];
$yayloyalty_earned   = $view['earned_worth'];
$yayloyalty_pages    = $yayloyalty_page['pages'];
$yayloyalty_columns  = [
    'friend' => __( 'Friend', 'yayloyalty' ),
    'status' => __( 'Status', 'yayloyalty' ),
    'date'   => __( 'Invited', 'yayloyalty' ),
    'earned' => __( 'You earned', 'yayloyalty' ),
];
?>
<div class="yayloyalty-account yayloyalty-referrals">
    <section class="yayloyalty-account__section yayloyalty-referrals__offer">
        <div class="yayloyalty-account__section-head">
            <h3 class="yayloyalty-account__title"><?php echo esc_html( ReferralAccountText::heading( $yayloyalty_settings ) ); ?></h3>
            <?php if ( null !== $yayloyalty_worth ) : ?>
                <?php /* translators: %s: money amount */ ?>
                <span class="yayloyalty-referrals__worth"><?php echo esc_html( sprintf( __( 'worth %s to you', 'yayloyalty' ), LauncherCopy::money( $yayloyalty_worth ) ) ); ?></span>
            <?php endif; ?>
        </div>
        <p class="yayloyalty-account__hint"><?php echo esc_html( ReferralAccountText::sentence( $yayloyalty_settings ) ); ?></p>

        <label class="yayloyalty-referrals__label" for="yayloyalty-referral-link"><?php esc_html_e( 'Your link', 'yayloyalty' ); ?></label>
        <div class="yayloyalty-referrals__link" data-yl-referral-link>
            <input id="yayloyalty-referral-link" class="yayloyalty-referrals__input" type="text" dir="ltr" readonly value="<?php echo esc_attr( $view['link'] ); ?>">
            <button type="button" class="yayloyalty-account__button yayloyalty-referrals__copy" data-copied="<?php esc_attr_e( 'Copied', 'yayloyalty' ); ?>"><?php esc_html_e( 'Copy link', 'yayloyalty' ); ?></button>
            <span class="screen-reader-text" aria-live="polite"></span>
        </div>

        <div class="yayloyalty-referrals__counts">
            <div class="yayloyalty-referrals__count">
                <span class="yayloyalty-referrals__count-label"><?php esc_html_e( 'Invited', 'yayloyalty' ); ?></span>
                <span class="yayloyalty-referrals__count-value"><?php echo esc_html( number_format_i18n( (int) $yayloyalty_counts['referred'] ) ); ?></span>
            </div>
            <div class="yayloyalty-referrals__count">
                <span class="yayloyalty-referrals__count-label"><?php esc_html_e( 'Ordered', 'yayloyalty' ); ?></span>
                <span class="yayloyalty-referrals__count-value"><?php echo esc_html( number_format_i18n( (int) $yayloyalty_counts['ordered'] ) ); ?></span>
            </div>
            <div class="yayloyalty-referrals__count">
                <span class="yayloyalty-referrals__count-label"><?php esc_html_e( 'Points earned', 'yayloyalty' ); ?></span>
                <span class="yayloyalty-referrals__count-value">
                    <?php echo esc_html( LauncherCopy::points( (int) $yayloyalty_counts['points_earned'] ) ); ?>
                    <?php if ( null !== $yayloyalty_earned ) : ?>
                        <span class="yayloyalty-referrals__count-sub">(<?php echo esc_html( LauncherCopy::money( $yayloyalty_earned ) ); ?>)</span>
                    <?php endif; ?>
                </span>
            </div>
            <?php if ( $yayloyalty_counts['on_the_way'] > 0 ) : ?>
                <div class="yayloyalty-referrals__count">
                    <span class="yayloyalty-referrals__count-label"><?php esc_html_e( 'On the way', 'yayloyalty' ); ?></span>
                    <span class="yayloyalty-referrals__count-value">
                        <?php echo esc_html( LauncherCopy::points( (int) $yayloyalty_counts['on_the_way'] ) ); ?>
                        <?php if ( null !== $yayloyalty_counts['next_release'] ) : ?>
                            <span class="yayloyalty-referrals__count-sub">· <?php echo esc_html( Dates::to_site_date( $yayloyalty_counts['next_release'] ) ); ?></span>
                        <?php endif; ?>
                    </span>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="yayloyalty-account__section">
        <h3 class="yayloyalty-account__title"><?php esc_html_e( 'Your invitations', 'yayloyalty' ); ?></h3>
        <?php if ( 0 === (int) $yayloyalty_counts['referred'] ) : ?>
            <div class="yayloyalty-account__empty">
                <p class="yayloyalty-account__empty-text"><?php esc_html_e( "No invitations yet. Share your link and they'll appear here.", 'yayloyalty' ); ?></p>
            </div>
        <?php else : ?>
            <div class="yayloyalty-account__table-wrap">
                <table class="yayloyalty-account__table">
                    <thead>
                        <tr>
                            <th scope="col"><?php echo esc_html( $yayloyalty_columns['friend'] ); ?></th>
                            <th scope="col"><?php echo esc_html( $yayloyalty_columns['status'] ); ?></th>
                            <th scope="col"><?php echo esc_html( $yayloyalty_columns['date'] ); ?></th>
                            <th scope="col" class="yayloyalty-account__num"><?php echo esc_html( $yayloyalty_columns['earned'] ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $yayloyalty_page['items'] as $yayloyalty_row ) : ?>
                            <tr>
                                <td data-title="<?php echo esc_attr( $yayloyalty_columns['friend'] ); ?>" dir="ltr"><?php echo esc_html( ReferralAccountText::mask_email( (string) $yayloyalty_row['friend']['email'] ) ); ?></td>
                                <td data-title="<?php echo esc_attr( $yayloyalty_columns['status'] ); ?>"><?php echo esc_html( ReferralAccountText::status( $yayloyalty_row ) ); ?></td>
                                <td class="yayloyalty-account__date" data-title="<?php echo esc_attr( $yayloyalty_columns['date'] ); ?>"><?php echo esc_html( Dates::to_site_date( (string) $yayloyalty_row['created_at'] ) ); ?></td>
                                <td class="yayloyalty-account__num" data-title="<?php echo esc_attr( $yayloyalty_columns['earned'] ); ?>"><?php echo esc_html( ReferralAccountText::earned( $yayloyalty_row ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ( $yayloyalty_pages > 1 ) : ?>
                <nav class="yayloyalty-account__pages" aria-label="<?php esc_attr_e( 'Invitation pages', 'yayloyalty' ); ?>">
                    <?php if ( $yayloyalty_page['page'] > 1 ) : ?>
                        <a class="yayloyalty-account__page" href="<?php echo esc_url( AccountReferrals::url( $yayloyalty_page['page'] - 1 ) ); ?>"><?php esc_html_e( 'Previous', 'yayloyalty' ); ?></a>
                    <?php endif; ?>
                    <span class="yayloyalty-account__page" aria-current="page"><?php echo esc_html( number_format_i18n( $yayloyalty_page['page'] ) . ' / ' . number_format_i18n( $yayloyalty_pages ) ); ?></span>
                    <?php if ( $yayloyalty_page['page'] < $yayloyalty_pages ) : ?>
                        <a class="yayloyalty-account__page" href="<?php echo esc_url( AccountReferrals::url( $yayloyalty_page['page'] + 1 ) ); ?>"><?php esc_html_e( 'Next', 'yayloyalty' ); ?></a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
        <p class="yayloyalty-account__hint"><?php esc_html_e( "Email addresses are partly hidden to protect your friends' privacy.", 'yayloyalty' ); ?></p>
    </section>
</div>
