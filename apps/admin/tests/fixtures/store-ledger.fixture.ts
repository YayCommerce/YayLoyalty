import type { StoreLedgerEntry } from '@/lib/schema/store-ledger.schema';

/** A store-ledger row with sensible defaults; override only what a test reads. */
export function storeLedgerEntry(overrides: Partial<StoreLedgerEntry> = {}): StoreLedgerEntry {
  return {
    id: 54,
    user_id: 1,
    points: 20,
    balance_after: 120,
    type: 'earn',
    source: 'order',
    reference_type: 'order',
    reference_id: 751,
    campaign_id: 15,
    redemption_id: null,
    order_id: 751,
    note: null,
    customer_note: null,
    actor_id: null,
    created_at: '2026-10-12 02:28:53',
    customer_name: 'Ada Lovelace',
    customer_email: 'ada@example.com',
    ...overrides,
  };
}
