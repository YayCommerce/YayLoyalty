<?php
namespace YayLoyalty\Pro\Birthday;

use YayLoyalty\Enums\CampaignTrigger;
use YayLoyalty\Repositories\CampaignRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Utils\Dates;
use YayLoyalty\Utils\Log;

defined( 'ABSPATH' ) || exit;

/**
 * The optional birthday field on WooCommerce's My Account registration and Account details forms, shown only
 * while a birthday campaign is live. Month and day only: the year earns nothing and is personal data.
 */
final class BirthdayFields {

    /** @var bool Whether the Account details field was printed, so the fallback hook doesn't print it twice. */
    private $is_edit_account_rendered = false;

    public const FIELD_MONTH = 'yayloyalty_birthday_month';

    public const FIELD_DAY = 'yayloyalty_birthday_day';

    /** input-text gives the selects the theme's text-field look; some themes then drop the dropdown arrow, which this brings back. */
    private const SELECT_STYLE = 'appearance: menulist-button; width: 100%;';

    public function register(): void {
        add_action( 'woocommerce_register_form', [ $this, 'render_register' ] );
        add_filter( 'woocommerce_registration_errors', [ $this, 'validate' ] );
        add_action( 'woocommerce_created_customer', [ $this, 'save' ] );

        // Beside the email address; a theme's older copy of the template may lack that hook, so it falls back to the form's end.
        add_action( 'woocommerce_edit_account_form_fields', [ $this, 'render_edit_account' ] );
        add_action( 'woocommerce_edit_account_form', [ $this, 'render_edit_account' ] );
        add_action( 'woocommerce_save_account_details_errors', [ $this, 'validate' ] );
        add_action( 'woocommerce_save_account_details', [ $this, 'save' ] );
    }

    /** Whether customers are asked for a birthday: a birthday campaign is live. */
    public static function is_offered(): bool {
        return [] !== ( new CampaignRepository() )->live_for_trigger( CampaignTrigger::BIRTHDAY, Dates::now() );
    }

    public function render_register(): void {
        if ( self::is_offered() ) {
            self::render( null, null );
        }
    }

    public function render_edit_account(): void {
        if ( $this->is_edit_account_rendered || ! self::is_offered() ) {
            return;
        }

        $this->is_edit_account_rendered = true;

        $customer = ( new CustomerRepository() )->find_by_user_id( get_current_user_id() );

        self::render(
            null !== $customer ? $customer->get_birthday_month() : null,
            null !== $customer ? $customer->get_birthday_day() : null
        );
    }

    /**
     * Adds the invalid-birthday error. A filter on registration and an action on Account details, which ignores the return.
     *
     * @param \WP_Error $errors
     * @return \WP_Error
     */
    public function validate( $errors ) {
        if ( $errors instanceof \WP_Error && self::is_rejected() ) {
            $errors->add( 'yayloyalty_birthday', __( 'Please enter a valid birthday.', 'yayloyalty' ) );
        }

        return $errors;
    }

    /**
     * Stores the posted birthday. Nothing when the form had no birthday field, as checkout's account creation
     * has not, or when no birthday campaign is live any more, as when one was paused while the form was open.
     */
    public function save( $user_id ): void {
        if ( ! self::is_posted() || ! self::is_offered() ) {
            return;
        }

        $birthday = self::posted();

        if ( null === $birthday ) {
            return;
        }

        try {
            ( new CustomerRepository() )->set_birthday( (int) $user_id, $birthday['month'], $birthday['day'] );
        } catch ( \Throwable $e ) {
            Log::error( 'BirthdayFields', sprintf( 'saving the birthday of user %d failed: %s', (int) $user_id, $e->getMessage() ) );
        }
    }

    /** Whether a posted birthday field holds no real date while the field is offered. */
    private static function is_rejected(): bool {
        return self::is_posted() && self::is_offered() && null === self::posted();
    }

    private static function is_posted(): bool {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified its form nonce before this hook.
        return isset( $_POST[ self::FIELD_MONTH ] ) || isset( $_POST[ self::FIELD_DAY ] );
    }

    /**
     * The posted birthday: both parts, or both null when left blank; null when it is not a real date.
     * Feb 29 is valid: it is checked against a leap year.
     *
     * @return array{month: ?int, day: ?int}|null
     */
    private static function posted(): ?array {
        list( $month, $day ) = self::raw_posted();

        if ( 0 === $month && 0 === $day ) {
            return [
                'month' => null,
                'day'   => null,
            ];
        }

        if ( 0 === $month || 0 === $day || ! checkdate( $month, $day, 2000 ) ) {
            return null;
        }

        return [
            'month' => $month,
            'day'   => $day,
        ];
    }

    /**
     * The posted month and day as integers, 0 for a part left blank or absent.
     *
     * @return array{0: int, 1: int}
     */
    private static function raw_posted(): array {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified its form nonce before this hook; a render only redisplays the visitor's own input.
        return [
            isset( $_POST[ self::FIELD_MONTH ] ) ? absint( wp_unslash( $_POST[ self::FIELD_MONTH ] ) ) : 0,
            isset( $_POST[ self::FIELD_DAY ] ) ? absint( wp_unslash( $_POST[ self::FIELD_DAY ] ) ) : 0,
        ];
        // phpcs:enable WordPress.Security.NonceVerification.Missing
    }

    /** The field, preselecting what was just posted, else $month and $day. */
    private static function render( ?int $month, ?int $day ): void {
        global $wp_locale;

        if ( self::is_posted() ) {
            list( $month, $day ) = self::raw_posted();
        }
        ?>
        <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide yayloyalty-birthday-field">
            <label for="<?php echo esc_attr( self::FIELD_MONTH ); ?>">
                <?php esc_html_e( 'Birthday', 'yayloyalty' ); ?>&nbsp;<span class="optional"><?php esc_html_e( '(optional)', 'yayloyalty' ); ?></span>
            </label>
            <span style="display: flex; gap: 8px;">
            <select class="woocommerce-Input input-text" style="<?php echo esc_attr( self::SELECT_STYLE ); ?>" name="<?php echo esc_attr( self::FIELD_MONTH ); ?>" id="<?php echo esc_attr( self::FIELD_MONTH ); ?>" aria-label="<?php esc_attr_e( 'Birthday month', 'yayloyalty' ); ?>">
                <option value=""><?php esc_html_e( 'Month', 'yayloyalty' ); ?></option>
                <?php for ( $option = 1; $option <= 12; $option++ ) : ?>
                    <option value="<?php echo esc_attr( (string) $option ); ?>" <?php selected( $month, $option ); ?>><?php echo esc_html( $wp_locale->get_month( $option ) ); ?></option>
                <?php endfor; ?>
            </select>
            <select class="woocommerce-Input input-text" style="<?php echo esc_attr( self::SELECT_STYLE ); ?>" name="<?php echo esc_attr( self::FIELD_DAY ); ?>" id="<?php echo esc_attr( self::FIELD_DAY ); ?>" aria-label="<?php esc_attr_e( 'Birthday day', 'yayloyalty' ); ?>">
                <option value=""><?php esc_html_e( 'Day', 'yayloyalty' ); ?></option>
                <?php for ( $option = 1; $option <= 31; $option++ ) : ?>
                    <option value="<?php echo esc_attr( (string) $option ); ?>" <?php selected( $day, $option ); ?>><?php echo esc_html( (string) $option ); ?></option>
                <?php endfor; ?>
            </select>
            </span>
            <span><em><?php esc_html_e( "We'll add bonus points on your birthday.", 'yayloyalty' ); ?></em></span>
        </p>
        <?php
    }
}
