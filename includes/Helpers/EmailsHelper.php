<?php
namespace YayLoyalty\Helpers;

use YayLoyalty\Engine\Emails\LoyaltyEmail;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and saves the Emails settings page. Each email's on/off, subject, heading and template are
 * that LoyaltyEmail's own WooCommerce settings. The weekly throttle lives in plugin settings.
 * Building WooCommerce's mailer constructs every WooCommerce email, so only /emails requests do it.
 */
class EmailsHelper {

    /**
     * The registered loyalty emails grouped as WooCommerce groups them, the
     * sender card, and the weekly throttle.
     *
     * @return array{groups: array<int, array<string, mixed>>, sending: array<string, string>, throttle_per_week: int|null}
     */
    public static function get_emails(): array {
        $groups = [];

        foreach ( self::registered_emails() as $email ) {
            $group = $email->email_group;

            if ( ! isset( $groups[ $group ] ) ) {
                $groups[ $group ] = [
                    'group'       => $group,
                    'group_label' => $email->get_email_group_title(),
                    'items'       => [],
                ];
            }

            $groups[ $group ]['items'][] = self::to_row( $email );
        }

        return [
            'groups'            => array_values( $groups ),
            'sending'           => self::get_sender(),
            'throttle_per_week' => SettingsHelper::get_settings()['emails']['throttle_per_week'],
        ];
    }

    /**
     * Saves only what the patch carries: `emails` (id => on/off) and
     * `throttle_per_week` (SettingsHelper coerces it). Returns get_emails().
     *
     * @param array{emails?: array<string, mixed>, throttle_per_week?: int|string|null} $patch
     */
    public static function update_emails( array $patch ): array {
        self::set_enabled( $patch['emails'] ?? [] );

        // array_key_exists: a null throttle (no cap) is a value to save, not a missing one.
        if ( array_key_exists( 'throttle_per_week', $patch ) ) {
            SettingsHelper::update_settings( [ 'emails' => [ 'throttle_per_week' => $patch['throttle_per_week'] ] ] );
        }

        return self::get_emails();
    }

    /**
     * The loyalty emails this build registered with WooCommerce, keyed by id.
     * update_emails() writes only these.
     *
     * @return array<string, LoyaltyEmail>
     */
    public static function registered_emails(): array {
        if ( ! function_exists( 'WC' ) ) {
            return [];
        }

        return array_filter(
            WC()->mailer()->get_emails(),
            static function ( $email ) {
                return $email instanceof LoyaltyEmail;
            }
        );
    }

    /**
     * Writes WooCommerce's own option, the one its Emails screen edits. Ids that
     * are not a registered loyalty email are ignored.
     *
     * @param array<string, mixed> $enabled_by_id Email id => on/off.
     */
    private static function set_enabled( array $enabled_by_id ): void {
        foreach ( self::registered_emails() as $email ) {
            if ( ! array_key_exists( $email->id, $enabled_by_id ) ) {
                continue;
            }

            $yes_or_no = rest_sanitize_boolean( $enabled_by_id[ $email->id ] ) ? 'yes' : 'no';
            $email->update_option( 'enabled', $yes_or_no );
            // WC_Email reads `enabled` once, when built, so keep this request's instance in step with what was saved.
            $email->enabled = $yes_or_no;
        }
    }

    /**
     * WooCommerce's own from-name and address plus its Emails settings URL, read live and never stored here.
     * Blank strings, not a fatal, when WooCommerce is inactive.
     *
     * @return array{from_name: string, from_address: string, wc_emails_url: string}
     */
    private static function get_sender(): array {
        $mailer = function_exists( 'WC' ) ? WC()->mailer() : null;

        return [
            'from_name'     => $mailer ? (string) $mailer->get_from_name() : '',
            'from_address'  => $mailer ? (string) $mailer->get_from_address() : '',
            'wc_emails_url' => self::settings_url(),
        ];
    }

    /** One row of the Emails table; `edit_url` opens this email's own WooCommerce settings screen. */
    private static function to_row( LoyaltyEmail $email ): array {
        return [
            'id'          => $email->id,
            'title'       => $email->get_title(),
            'description' => $email->get_description(),
            'recipient'   => $email->is_customer_email() ? 'customer' : 'admin',
            'enabled'     => $email->is_enabled(),
            'edit_url'    => self::settings_url( $email->id ),
        ];
    }

    /** WooCommerce's Emails settings list, or one email's screen when a section (its id) is given. */
    private static function settings_url( string $section = '' ): string {
        $path = 'admin.php?page=wc-settings&tab=email';

        if ( '' !== $section ) {
            $path .= '&section=' . $section;
        }

        return esc_url_raw( admin_url( $path ) );
    }
}
