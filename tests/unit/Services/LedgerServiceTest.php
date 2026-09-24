<?php
namespace YayLoyalty\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;

/**
 * What the award writer still owes a proof. The guard itself, the
 * balance_after chain, the aggregates a duplicate must not move and the
 * non-idempotence of manual rows are now asserted against a real server in
 * `tests/integration/Services/LedgerGuardTest.php`; what is left here needs
 * either two connections or a body that does not exist yet.
 */
final class LedgerServiceTest extends TestCase {

    public function test_earn_locks_the_customer_row_before_inserting(): void {
        $this->markTestIncomplete( 'Two concurrent earns for one user must serialise on SELECT … FOR UPDATE. Needs a second connection the harness cannot drive yet.' );
    }

    public function test_duplicate_leaves_pending_untouched(): void {
        $this->markTestIncomplete( 'A duplicate earn must not release staged pending points. Needs PendingPoints, still a TODO(F01-4) stub.' );
    }

    public function test_redeem_shares_the_earn_write_path_and_never_goes_below_zero(): void {
        $this->markTestIncomplete( 'redeem(): same ensure_row → lock → insert → aggregates as earn(); locked balance + points < 0 → Outcome::skipped( insufficient_balance ), nothing written; a duplicate key → Outcome::duplicate. Stub until F08.' );
    }

    public function test_ledger_recorded_fires_after_the_outermost_commit(): void {
        $this->markTestIncomplete( 'Nested in an outer Transaction::run(), yayloyalty_ledger_recorded fires after the outer commit, not the savepoint release — the known gap in dispatch_events(). The unnested case is covered in the integration suite.' );
    }
}
