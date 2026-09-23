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
 * Writes `yayloyalty_customer_meta` rows for the three captured activity
 * events. [REVIEW] (docs/code-standards.md § 8): a third class holding both
 * `CustomerRepository` and `LedgerRepository` alongside
 * `CustomerMetaRepository` — justified because both are READ-ONLY here
 * (membership check, balance, kept-entries count) and the only write
 * target is `customer_meta`.
 *
 * Never opens `Transaction::run()`: every write here is a single-table
 * insert with nothing to make atomic, and the account-deletion path may
 * run alongside a foreign transaction already open on the same connection
 * during `wp_delete_user()`.
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
     * One `account_deleted` row capturing identity + real deletion facts.
     * Writes nothing for a non-member. Idempotent via `has()`-then-insert()
     * — an accepted race, not a hard guarantee: two near-simultaneous fires
     * can both pass the check before either insert commits; `latest()`
     * (id DESC) still resolves `deleted_at` deterministically either way.
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

        // TODO: the email and display name are kept deliberately for now, so staff can identify a deleted
        // member. No privacy eraser reaches them yet; register one on wp_privacy_personal_data_erasers
        // (plus an exporter) before relying on this for GDPR erasure requests.
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
     * "First loyalty activity", not "WP registered": the `customers` row now
     * exists from registration, but this entry waits for the first ledger
     * write. Writes once per user; accepted race under concurrency
     * — this table's shape has no unique key to prevent a duplicate, only
     * `has()` to make it unlikely (cosmetic only, no backfill for members
     * that already exist).
     */
    public function record_account_created( LedgerEntry $entry ): void {
        $user_id = $entry->get_user_id();

        if ( $this->meta->has( $user_id, ActivityEvent::ACCOUNT_CREATED ) ) {
            return;
        }

        $data = [
            'points' => $entry->get_points(),
            'source' => $entry->get_source(),
        ];

        $this->meta->insert(
            // The ledger row's own timestamp, not Dates::now(): so the activity
            // log and the ledger tab agree to the second on when this happened.
            CustomerMeta::event( $user_id, ActivityEvent::ACCOUNT_CREATED, $data, ActorKind::AUTOMATIC, null, false, $entry->get_created_at() )
        );
    }

    /**
     * Only a manual admin adjustment (ADJUST + MANUAL) — every other ledger
     * row is the ledger tab's business, not the activity log's.
     * `staff_only` is a display flag only (the row quotes an internal admin
     * note), never access control.
     */
    public function record_points_adjusted( LedgerEntry $entry ): void {
        if ( LedgerType::ADJUST !== $entry->get_type() || LedgerSource::MANUAL !== $entry->get_source() ) {
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

    /** Snapshotted at write time, never resolved later — a later admin deletion cannot blank the audit trail. */
    private function actor_name( int $actor_id ): ?string {
        $user = get_userdata( $actor_id );

        return ( $user instanceof \WP_User && '' !== $user->display_name ) ? $user->display_name : null;
    }
}
