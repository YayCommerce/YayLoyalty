<?php
namespace YayLoyalty\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Emails storage: one option, `{emails: {id => bool}, throttle_per_week: int}`. The bool map is
 * keyed by EmailsMetadata::all_email_ids() (Free and Pro share one persisted store).
 * Every build receives and can save every id; only which ids the Lite UI renders editable differs
 * client-side (FREE_EMAIL_IDS + IS_PRO_BUILD). This mirrors the existing Design precedent, where
 * Pro schema fields are already accepted server-side unconditionally — the Free/Pro split is
 * enforced by what Lite bundles into its UI, not by a server-side license check
 * (docs/code-standards.md §13).
 */
class EmailsHelper {

    public const OPTION_EMAILS             = 'yayloyalty_emails';
    public const DEFAULT_THROTTLE_PER_WEEK = 3;

    /** Raw option, both sub-keys defaulted and type-guarded — never a corrupt shape past this point. */
    private static function stored(): array {
        $raw = get_option( self::OPTION_EMAILS, [] );
        $raw = is_array( $raw ) ? $raw : [];

        return [
            'emails'            => is_array( $raw['emails'] ?? null ) ? $raw['emails'] : [],
            'throttle_per_week' => $raw['throttle_per_week'] ?? self::DEFAULT_THROTTLE_PER_WEEK,
        ];
    }

    /** Stored bool map merged over defaults for every known id; unknown keys dropped, values cast to bool. */
    public static function get_emails(): array {
        $saved = self::stored()['emails'];

        $emails = [];

        foreach ( EmailsMetadata::all_defaults() as $id => $default ) {
            $emails[ $id ] = array_key_exists( $id, $saved ) ? (bool) $saved[ $id ] : $default;
        }

        return $emails;
    }

    /**
     * Whitelists to the known ids, casts to bool, merges over the currently stored value so a
     * partial POST is a patch, not a wipe. Unknown keys are silently dropped. Leaves
     * throttle_per_week untouched.
     */
    public static function update_emails( array $emails ): array {
        $current  = self::stored();
        $to_store = self::get_emails();

        foreach ( EmailsMetadata::all_email_ids() as $id ) {
            if ( array_key_exists( $id, $emails ) ) {
                $to_store[ $id ] = (bool) $emails[ $id ];
            }
        }

        $current['emails'] = $to_store;
        update_option( self::OPTION_EMAILS, $current, false );

        return self::get_emails();
    }

    /** Weekly per-customer send cap; not enforced yet (no trigger/cron sends emails this release) — saved for that future task. */
    public static function get_throttle_per_week(): int {
        return max( 0, (int) self::stored()['throttle_per_week'] );
    }

    /** Leaves the emails bool map untouched. @return int The clamped, stored value. */
    public static function update_throttle_per_week( int $per_week ): int {
        $value = max( 0, $per_week );

        $current                      = self::stored();
        $current['throttle_per_week'] = $value;
        update_option( self::OPTION_EMAILS, $current, false );

        return $value;
    }

    /**
     * WooCommerce's own from-name/address plus its Emails settings URL, read live every request —
     * never stored here. Blank strings (not a fatal) when WooCommerce is inactive.
     *
     * @return array{from_name: string, from_address: string, wc_emails_url: string}
     */
    public static function sending(): array {
        $mailer = function_exists( 'WC' ) ? WC()->mailer() : null;

        return [
            'from_name'     => $mailer ? (string) $mailer->get_from_name() : '',
            'from_address'  => $mailer ? (string) $mailer->get_from_address() : '',
            // No section anchor: no WC_Email subclass is registered yet, so this lands on the
            // generic list. A future trigger task registering WC_Email classes can add one.
            'wc_emails_url' => esc_url_raw( admin_url( 'admin.php?page=wc-settings&tab=email' ) ),
        ];
    }

    /**
     * Merged GET/POST response: every email grouped by category with its live enabled state and
     * whether it's part of the Free set (isFree) — the FE decides final editability as
     * `isFree || IS_PRO_BUILD`.
     *
     * @return array{groups: array<int, array<string, mixed>>, sending: array<string, string>, throttlePerWeek: int}
     */
    public static function get_response(): array {
        $enabled = self::get_emails();

        $groups = [];

        foreach ( EmailsMetadata::pro_email_groups() as $group => $data ) {
            $items = [];

            foreach ( $data['items'] as $id => $item ) {
                $items[] = [
                    'id'          => $id,
                    'label'       => $item['label'],
                    'description' => $item['description'],
                    'recipient'   => $item['recipient'],
                    'enabled'     => $enabled[ $id ],
                    'isFree'      => in_array( $id, EmailsMetadata::FREE_EMAIL_IDS, true ),
                ];
            }

            $groups[] = [
                'group'      => $group,
                'groupLabel' => $data['label'],
                'items'      => $items,
            ];
        }

        return [
            'groups'          => $groups,
            'sending'         => self::sending(),
            'throttlePerWeek' => self::get_throttle_per_week(),
        ];
    }
}
