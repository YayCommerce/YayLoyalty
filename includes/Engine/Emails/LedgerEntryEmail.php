<?php
namespace YayLoyalty\Engine\Emails;

use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Repositories\LedgerRepository;

defined( 'ABSPATH' ) || exit;

/** Base for loyalty emails about one ledger row, which their email action passes as its id. */
abstract class LedgerEntryEmail extends LoyaltyEmail {

    /** @var LedgerEntry|null The row $values were built for. */
    private $values_entry = null;

    /** @var array<string, mixed> */
    private $values = [];

    /** Mails the row's customer, within the weekly cap, when this email accepts the row. */
    public function trigger( int $entry_id ): void {
        $entry = ( new LedgerRepository() )->find( $entry_id );

        if ( ! $entry instanceof LedgerEntry || ! $this->accepts( $entry ) ) {
            return;
        }

        // is_enabled()'s filter receives $this->object, which must be this row.
        $this->object = $entry;

        if ( ! $this->is_enabled() ) {
            return;
        }

        $user = get_userdata( $entry->get_user_id() );

        if ( ! $user ) {
            return;
        }

        // Before the placeholders: the default point labels are translated strings.
        $this->setup_locale();

        try {
            $this->prepare( $entry );
            $this->recipient = $user->user_email;

            if ( $this->get_recipient() ) {
                $this->send_to_customer( $entry->get_user_id() );
            }
        } finally {
            // A throwing mail transport must not leave the rest of the request in the customer's locale.
            $this->restore_locale();
        }
    }

    /** Whether this email reports $entry. The action takes any row id, so each email checks its own kind. */
    abstract protected function accepts( LedgerEntry $entry ): bool;

    /**
     * The subject placeholders' values, which the body shows too.
     *
     * @return array<string, string>
     */
    abstract protected function amounts( LedgerEntry $entry ): array;

    /**
     * Every template variable for $entry, amounts() included.
     *
     * @return array<string, mixed>
     */
    abstract protected function build_values( LedgerEntry $entry ): array;

    /** Points the subject placeholders and template variables at $entry. */
    protected function prepare( LedgerEntry $entry ): void {
        $this->object = $entry;

        foreach ( $this->amounts( $entry ) as $key => $value ) {
            $this->placeholders[ '{' . $key . '}' ] = $value;
        }
    }

    /** Built once per row: a multipart send and YayMail each render it again. */
    protected function extra_template_args(): array {
        if ( ! $this->object instanceof LedgerEntry ) {
            return [];
        }

        if ( $this->values_entry !== $this->object ) {
            $this->values_entry = $this->object;
            $this->values       = $this->build_values( $this->object );
        }

        return $this->values;
    }
}
