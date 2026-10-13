<?php
namespace YayLoyalty\Services;

use YayLoyalty\Enums\ActivityEvent;
use YayLoyalty\Enums\ActorKind;
use YayLoyalty\Enums\LedgerSource;
use YayLoyalty\Enums\LedgerType;
use YayLoyalty\Models\CustomerMeta;
use YayLoyalty\Models\LedgerEntry;
use YayLoyalty\Repositories\CustomerMetaRepository;
use YayLoyalty\Repositories\CustomerRepository;
use YayLoyalty\Repositories\LedgerRepository;
use YayLoyalty\Utils\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Writes `yayloyalty_customer_meta` rows for the three captured activity events. It reads
 * CustomerRepository and LedgerRepository (membership, balance, kept-entries count) but writes
 * only `customer_meta`.
 *
 * It never opens Transaction::run(): each write is a single-table insert with nothing to make atomic,
 * and the account-deletion path may run inside a foreign transaction during wp_delete_user().
 */
final class CustomerActivityService {

    /** @var CustomerMetaRepository */
    private $meta;

    /** @var CustomerRepository Read-only: membership check, balance at deletion. */
    private $customers;

    /** @var LedgerRepository Read-only: kept-entries count. */
    private $ledger;

    public function __construct( CustomerMetaRepository $meta, CustomerRepository $customers, LedgerRepository $ledger ) {
        $this->meta      = $meta;
        $this->customers = $customers;
        $this->ledger    = $ledger;
    }

    /**
     * One `account_deleted` row with the member's identity and deletion facts.
     * Writes nothing for a non-member. has()-then-insert() is an accepted race,
     * not a guarantee: two near-simultaneous calls can both pass the check.
     * latest() (id DESC) still resolves the deletion deterministically.
     */
    public function record_account_deleted( int $user_id, string $email, string $display_name ): void {
        $customer = $this->customers->find_by_user_id( $user_id );

        if ( null === $customer ) {
            return;
        }

        if ( $this->meta->has( $user_id, ActivityEvent::ACCOUNT_DELETED ) ) {
            return;
        }

        $kept = $this->ledger->count_for_user( $user_id );

        list( $actor_kind, $actor_name ) = $this->actor( get_current_user_id() );

        // The email and display name are kept on purpose, so staff can identify a deleted member.
        // TODO(privacy-eraser): register a wp_privacy_personal_data_erasers eraser and an exporter for this snapshot.
        $data = [
            'email'               => $email,
            'display_name'        => $display_name,
            'balance_at_deletion' => $customer->get_balance(),
            'kept_ledger_entries' => $kept,
        ];

        $this->meta->insert(
            CustomerMeta::event(
                $user_id,
                ActivityEvent::ACCOUNT_DELETED,
                $data,
                $actor_kind,
                $actor_name,
                false,
                Dates::now()
            )
        );
    }

    /**
     * One `membership_started` row on a member's first ledger write: first loyalty
     * activity, not WordPress registration. Written once per user, with the same
     * has()-then-insert() race as account_deleted, where a duplicate is only
     * cosmetic. Existing members get no backfill.
     */
    public function record_membership_started( LedgerEntry $entry ): void {
        $user_id = $entry->get_user_id();

        if ( $this->meta->has( $user_id, ActivityEvent::MEMBERSHIP_STARTED ) ) {
            return;
        }

        $data = [
            'points' => $entry->get_points(),
            'source' => $entry->get_source(),
        ];

        $this->meta->insert(
            // The ledger row's own timestamp, not Dates::now(), so the activity
            // log and the ledger tab agree to the second.
            CustomerMeta::event( $user_id, ActivityEvent::MEMBERSHIP_STARTED, $data, ActorKind::AUTOMATIC, null, false, $entry->get_created_at() )
        );
    }

    /**
     * Records only a manual admin adjustment: every other ledger row belongs to
     * the ledger tab, not the activity log. The row is `staff_only` because it
     * quotes an internal admin note.
     */
    public function record_points_adjusted( LedgerEntry $entry ): void {
        if ( ! LedgerType::is_manual_adjustment( $entry->get_type() ) || LedgerSource::MANUAL !== $entry->get_source() ) {
            return;
        }

        $data = [
            'points' => $entry->get_points(),
            'note'   => $entry->get_note() ?? '',
        ];

        list( $actor_kind, $actor_name ) = $this->actor( $entry->get_actor_id() );

        $this->meta->insert(
            CustomerMeta::event(
                $entry->get_user_id(),
                ActivityEvent::POINTS_ADJUSTED,
                $data,
                $actor_kind,
                $actor_name,
                true,
                $entry->get_created_at()
            )
        );
    }

    /**
     * One `earning_limited` row per award an earning limit cut, in part or
     * whole (awarded 0). The held-back points are not owed later; this row is
     * the record that they were never paid.
     *
     * @param string $occurred_at UTC MySQL datetime: the written row's.
     */
    public function record_earning_limited( int $user_id, string $trigger, int $evaluated, int $held_back, ?int $order_id, string $occurred_at ): void {
        $data = [
            'trigger'   => $trigger,
            'evaluated' => $evaluated,
            'awarded'   => $evaluated - $held_back,
            'held_back' => $held_back,
            'order_id'  => $order_id,
        ];

        $this->meta->insert(
            CustomerMeta::event( $user_id, ActivityEvent::EARNING_LIMITED, $data, ActorKind::AUTOMATIC, null, false, $occurred_at )
        );
    }

    /**
     * One `expiring_soon_email_sent` row per reminder the expiry job sent. The drawer derives the deadline
     * from occurred_at plus window_days, the days that were left.
     *
     * @param string $occurred_at UTC MySQL datetime.
     */
    public function record_expiring_soon_email_sent( int $user_id, int $window_days, string $occurred_at ): void {
        $this->meta->insert(
            CustomerMeta::event( $user_id, ActivityEvent::EXPIRING_SOON_EMAIL_SENT, [ 'window_days' => $window_days ], ActorKind::AUTOMATIC, null, false, $occurred_at )
        );
    }

    /**
     * Kind + display name for a write's actor. `> 0` is the one rule for
     * "was this a person" — `get_current_user_id()` and a ledger row's
     * `actor_id` both use 0/null for a cron or system context.
     *
     * @return array{0: string, 1: string|null}
     */
    private function actor( ?int $actor_id ): array {
        if ( null === $actor_id || $actor_id <= 0 ) {
            return [ ActorKind::AUTOMATIC, null ];
        }

        return [ ActorKind::STAFF, $this->actor_name( $actor_id ) ];
    }

    /** Snapshotted at write time, never resolved later, so deleting the admin cannot blank the audit trail. */
    private function actor_name( int $actor_id ): ?string {
        $user = get_userdata( $actor_id );

        return ( $user instanceof \WP_User && '' !== $user->display_name ) ? $user->display_name : null;
    }
}
