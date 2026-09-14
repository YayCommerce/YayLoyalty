/**
 * Sort key/direction shapes for the Customers list toolbar's sort carets.
 *
 * The actual ordering now runs server-side — see `CustomerRowSort.php`
 * (mirrors this file's key list and tie-break rules exactly) and
 * `customers-page.api.ts` — so a sort applies across the whole (demo)
 * dataset, not just whatever page already loaded. This file only keeps the
 * shapes the UI needs (`CustomersTable`'s caret state, `defaultSortDirection`
 * for a newly-clicked column).
 */
export type SortKey = 'name' | 'tier' | 'balance' | 'pending' | 'lifetime' | 'last_activity' | 'expires' | 'referrals';
export type SortDirection = 'asc' | 'desc';

/** Text columns read naturally A→Z first; every numeric/date column reads biggest/most-recent first. */
export function defaultSortDirection(key: SortKey): SortDirection {
  return key === 'name' || key === 'tier' ? 'asc' : 'desc';
}
