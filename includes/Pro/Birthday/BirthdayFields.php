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

    public const FIELD_MONTH = 'yayloyalty_birthday_month';

    public const FIELD_DAY = 'yayloyalty_birthday_day';

    public function register(): void {
        add_action( 'woocommerce_register_form', [ $this, 'render_register' ] );
        add_filter( 'woocommerce_registration_errors', [ $this, 'validate_register' ] );
        add_action( 'woocommerce_created_customer', [ $this, 'save' ] );

        add_action( 'woocommerce_edit_account_form', [ $this, 'render_edit_account' ] );
        add_action( 'woocommerce_save_account_details_errors', [ $this, 'validate_edit_account' ] );
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
        if ( ! self::is_offered() ) {
            return;
        }

        $customer = ( new CustomerRepository() )->find_by_user_id( get_current_user_id() );

        self::render(
            null !== $customer ? $customer->get_birthday_month() : null,
            null !== $customer ? $customer->get_birthday_day() : null
        );
    }

    /**
     * @param \WP_Error $errors
     * @return \WP_Error
     */
    public function validate_register( $errors ) {
        if ( $errors instanceof \WP_Error && null === self::posted() ) {
            $errors->add( 'yayloyalty_birthday', self::invalid_message() );
        }

        return $errors;
    }

    /** @param \WP_Error $errors */
    public function validate_edit_account( $errors ): void {
        if ( $errors instanceof \WP_Error && null === self::posted() ) {
            $errors->add( 'yayloyalty_birthday', self::invalid_message() );
        }
    }

    /** Stores the posted birthday. Nothing when the form had no birthday field, as checkout's account creation has not. */
    public function save( $user_id ): void {
        if ( ! self::is_posted() ) {
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
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified its form nonce before this hook.
        $month = isset( $_POST[ self::FIELD_MONTH ] ) ? absint( wp_unslash( $_POST[ self::FIELD_MONTH ] ) ) : 0;
        $day   = isset( $_POST[ self::FIELD_DAY ] ) ? absint( wp_unslash( $_POST[ self::FIELD_DAY ] ) ) : 0;
        // phpcs:enable WordPress.Security.NonceVerification.Missing

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

    private static function invalid_message(): string {
        return __( 'Please enter a valid birthday.', 'yayloyalty' );
    }

    /** The field, preselecting what was just posted, else $month and $day. */
    private static function render( ?int $month, ?int $day ): void {
        global $wp_locale;

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- only redisplays the visitor's own input.
        if ( self::is_posted() ) {
            $month = isset( $_POST[ self::FIELD_MONTH ] ) ? absint( wp_unslash( $_POST[ self::FIELD_MONTH ] ) ) : 0;
            $day   = isset( $_POST[ self::FIELD_DAY ] ) ? absint( wp_unslash( $_POST[ self::FIELD_DAY ] ) ) : 0;
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        ?>
        <p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide yayloyalty-birthday-field">
            <label for="<?php echo esc_attr( self::FIELD_MONTH ); ?>">
                <?php esc_html_e( 'Birthday', 'yayloyalty' ); ?>&nbsp;<span class="optional"><?php esc_html_e( '(optional)', 'yayloyalty' ); ?></span>
            </label>
            <span style="display: flex; gap: 8px;">
            <select name="<?php echo esc_attr( self::FIELD_MONTH ); ?>" id="<?php echo esc_attr( self::FIELD_MONTH ); ?>" aria-label="<?php esc_attr_e( 'Birthday month', 'yayloyalty' ); ?>">
                <option value=""><?php esc_html_e( 'Month', 'yayloyalty' ); ?></option>
                <?php for ( $option = 1; $option <= 12; $option++ ) : ?>
                    <option value="<?php echo esc_attr( (string) $option ); ?>" <?php selected( $month, $option ); ?>><?php echo esc_html( $wp_locale->get_month( $option ) ); ?></option>
                <?php endfor; ?>
            </select>
            <select name="<?php echo esc_attr( self::FIELD_DAY ); ?>" id="<?php echo esc_attr( self::FIELD_DAY ); ?>" aria-label="<?php esc_attr_e( 'Birthday day', 'yayloyalty' ); ?>">
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
