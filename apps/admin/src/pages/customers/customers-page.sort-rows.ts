/**
 * Sort key/direction shapes for the Customers list toolbar's sort carets.
 *
 * The actual ordering — including tie-break rules like "no-expiry rows sort
 * last regardless of direction" — belongs entirely server-side, in
 * `CustomerRowSort.php` (which mirrors this file's key list, not the other
 * way round: no comparator lives here anymore), so a sort applies across the
 * whole dataset, not just whatever page already loaded. No real list
 * endpoint calls it yet — the demo one (`customers-page.api.ts`) was removed
 * ahead of it. This file only keeps the shapes the UI needs
 * (`CustomersTable`'s caret state, `defaultSortDirection` for a
 * newly-clicked column).
 */
export type SortKey = 'name' | 'tier' | 'balance' | 'pending' | 'lifetime' | 'last_activity' | 'expires' | 'referrals';
export type SortDirection = 'asc' | 'desc';

/** Text columns read naturally A→Z first; every numeric/date column reads biggest/most-recent first. */
export function defaultSortDirection(key: SortKey): SortDirection {
  return key === 'name' || key === 'tier' ? 'asc' : 'desc';
}
