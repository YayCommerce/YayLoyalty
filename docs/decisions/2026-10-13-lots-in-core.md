# Both builds keep points lots, and LedgerService writes them in the ledger row's transaction

**Purpose:** why `lots` and `lot_allocations` are core tables in both builds, and why only `LedgerService` writes them.

**Update when:** never edit it. Supersede it with a new record if lots become Pro-only, or another writer is allowed.

## Status

Accepted, 2026-10-13, by PhungTruong1997, with the [points expiry design](../specs/2026-10-13-points-expiry-design.md). It supersedes [the Pro-tables record](2026-10-09-pro-tables-via-schema-filter.md), which was never accepted.

## Context

- Points expire in both builds. Free expires a whole balance after a period without earning or redeeming. Pro can switch, once, to expiring each earning a set number of months after it was earned.
- Per-earning expiry needs a mutable "remaining" counter per earning. The ledger is append-only ([LedgerService is the only writer](2026-10-01-ledger-service-sole-writer.md)), so that counter lives in `lots`, and how each row took or returned points lives in `lot_allocations`.
- The earlier proposal kept both tables Pro-only, installed through `yayloyalty_table_schemas` and tracked by a `yayloyalty_db_tables` slug list. A Pro-only writer would also have needed a seam into `LedgerService`'s transaction, because `yayloyalty_ledger_recorded` fires after commit.
- Options turned down:
  - Pro-only tables with a filter install path: Free expiry needs the same data, and the install path and the seam add code that serves no other table.
  - A typed observer fetched through a filter, or an in-transaction action, as the seam: unnecessary once lots are core. An action would let any plugin run under the customer lock.
  - Writing lots only while expiry is on: turning it off then on leaves balances without lots.

## Decision

- `LotsTable` and `LotAllocationsTable` live in `includes/Database/Schema/` and are listed in `Installer::schemas()`, so both builds install them on activation and on a version bump. The installer tracks only `yayloyalty_db_version`.
- Every ledger row writes lots, whether or not expiry is on: a positive row opens one lot, and a negative row takes from open lots oldest first.
- Only `LedgerService` writes lots and allocations, through `LotWriter`, inside the ledger row's transaction under the customer lock. Nothing else constructs `LotWriter`.
- Per lot, `remaining = points − SUM(allocations.points)`. Per customer, `SUM(remaining of open lots) = max(balance, 0)`.

## Consequences

- Free and Pro expire points through one mechanism. Pro changes only the dates lots get.
- Every Lite site carries two more tables, and every ledger write does a few more queries under the lock.
- Lots stay consistent with the balance from the first write, so the expiry job can trust them.
- Balances that predate lots have none. The plugin is unreleased, so only local and staging sites hold such balances, and they reset their data.
