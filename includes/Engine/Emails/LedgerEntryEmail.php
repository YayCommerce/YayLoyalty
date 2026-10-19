<?php
namespace YayLoyalty\Engine\Emails;

use YayLoyalty\Helpers\SettingsHelper;
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
        $this->send_prepared(
            $entry->get_user_id(),
            $entry,
            function () use ( $entry ): void {
                $this->prepare( $entry );
            }
        );
    }

    /** Whether this email reports $entry. The action takes any row id, so each email checks its own kind. */
    abstract protected function accepts( LedgerEntry $entry ): bool;

    /**
     * The amounts of an award: the points earned and the balance after them.
     *
     * @return array{points: string, points_label: string, balance: string}
     */
    protected static function earned_amounts( LedgerEntry $entry ): array {
        return [
            'points'       => SettingsHelper::format_number( $entry->get_points() ),
            'points_label' => SettingsHelper::points_label( $entry->get_points() ),
            'balance'      => SettingsHelper::format_number( $entry->get_balance_after() ),
        ];
    }

    /** An award with points left: a deferred send can run after a refund or cancellation took them back. */
    protected static function has_points_left( LedgerEntry $entry ): bool {
        return $entry->get_points() > 0
            && ( new LedgerRepository() )->sum_refunded_for( $entry, false ) < $entry->get_points();
    }

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

    /**
     * amounts() plus the template variables every ledger email shares: the row, greeting and balance.
     *
     * @return array<string, mixed>
     */
    protected function balance_values( LedgerEntry $entry ): array {
        $greeting_name = self::greeting_name( $entry->get_user_id() );
        $balance_text  = SettingsHelper::points_text( $entry->get_balance_after() );
        $balance_value = self::balance_value( $entry->get_balance_after() );

        return $this->amounts( $entry ) + [
            'entry'              => $entry,
            'greeting_name'      => $greeting_name,
            'greeting_text'      => self::greeting_text( esc_html( $greeting_name ) ),
            'balance_text'       => $balance_text,
            'balance_sentence'   => self::balance_sentence( esc_html( $balance_text ) ),
            'balance_value'      => $balance_value,
            'balance_value_text' => self::balance_value_text( $balance_value ),
        ];
    }

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
