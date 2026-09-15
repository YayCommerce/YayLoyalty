import type { CustomerSortDirection, CustomerSortKey } from '@/lib/schema/customer.schema';

/**
 * Sort key/direction shapes for the Customers list toolbar's sort carets.
 *
 * The actual ordering — including tie-break rules like "no-expiry rows sort
 * last regardless of direction" — runs entirely server-side, in
 * `CustomerRowSort.php` via `CustomerRepository::paginate_list()` (see
 * `lib/api/customer.api.ts`). The value union itself is re-exported from
 * `lib/schema/customer.schema.ts`, since `lib/` code needs it too and must
 * not import from `pages/`. This file only keeps the shapes the UI needs
 * (`CustomersTable`'s caret state, `defaultSortDirection` for a
 * newly-clicked column).
 *
 * `tier`/`referrals` have no real backing data (no tiers table, no referral
 * count column) — `CustomersTable.tsx` no longer offers them as clickable
 * headers, but the type keeps both members so `defaultSortDirection` stays
 * total and a stray reference doesn't silently fall through.
 */
export type SortKey = CustomerSortKey;
export type SortDirection = CustomerSortDirection;

/** Text columns read naturally A→Z first; every numeric/date column reads biggest/most-recent first. */
export function defaultSortDirection(key: SortKey): SortDirection {
  return key === 'name' || key === 'tier' ? 'asc' : 'desc';
}
