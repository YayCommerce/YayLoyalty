# LedgerService is the only writer of the ledger and its cached totals

**Purpose:** why points move only through `LedgerService`, and why the customer row caches totals over an append-only ledger.

**Update when:** never edit it. Supersede it with a new record if another writer is allowed, or the ledger stops being the source of truth.

## Status

Accepted, 2026-10-01, by Trace Bui, who confirmed the reasons. Backfilled: the decision was made on 2026-09-09, in `c992f4e`.

## Context

- A points balance is a liability the merchant carries, so every movement has to be accounted for, and no event may award or spend twice.
- Points move for many reasons: orders, refunds, cancellations, reviews, signups, admin adjustments and, once built, redemptions. WooCommerce hooks can fire twice, orders bounce between statuses, and two requests for one customer can run at once.
- In MySQL, `SELECT … FOR UPDATE` on a row that doesn't exist yet takes a gap lock, not a row lock. Two first writers that each check, then create, both insert, and the totals split for good.
- The customer list sorts and filters by the balance and the lifetime totals, and the dashboard sums balances, so reads need those totals without summing the ledger. Every write also needs one row per customer to lock, and the balance on that row is what each new ledger row's running balance starts from.

## Decision

- The ledger is append-only, and it is the source of truth. The customer row caches totals over it: the balance and the lifetime points earned and redeemed. Replaying a customer's ledger rebuilds them.
- `LedgerService` (`includes/Services/LedgerService.php`) is the only code that writes ledger rows or those totals, and it writes both in one transaction. The order is fixed: make sure the customer row exists, lock it, check idempotency under the lock, append the row with its `balance_after` chained from the locked balance, then update the totals. Its event fires only after commit. [architecture.md](../architecture.md#server-layers-and-write-ownership) has the details.
- A row written for an event carries an idempotency key under a unique index. An admin adjustment carries none, on purpose: an admin may grant the same bonus twice, so the endpoint caps its size instead.
- This is a convention that PHP doesn't enforce. The class docblock lists every place allowed to construct the service.

## Consequences

- Locking, idempotency and the running balance are solved once. A new kind of movement, such as redemption or expiry, is a new method on the service, not a new write path.
- The ledger can be checked: each row's `balance_after` is the previous row's plus its points.
- The row lock serialises writes for one customer.
- Nothing stops new code from writing the tables directly. Review and the hard rule in `CLAUDE.md` are the guard, and the docblock's list has to stay current.
- A subscriber to the after-commit event can't roll the write back, and one that fails doesn't undo it.
