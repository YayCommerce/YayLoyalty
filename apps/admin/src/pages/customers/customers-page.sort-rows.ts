import type { MockCustomer } from './customers-page.mock-data';

/**
 * Real, client-side sort over the currently-loaded mock rows — the sort
 * carets in `CustomersTable` are wired to this, unlike every other control on
 * the page (search/filters/export stay no-ops: there is no larger dataset
 * behind them to re-fetch, so "sorting the page you're on" is honest UI-only
 * behaviour in a way "filtering the page you're on" would not be).
 */
export type SortKey = 'name' | 'tier' | 'balance' | 'pending' | 'lifetime' | 'last_activity' | 'expires' | 'referrals';
export type SortDirection = 'asc' | 'desc';

/** Text columns read naturally A→Z first; every numeric/date column reads biggest/most-recent first. */
export function defaultSortDirection(key: SortKey): SortDirection {
  return key === 'name' || key === 'tier' ? 'asc' : 'desc';
}

export function sortRows(rows: MockCustomer[], key: SortKey, direction: SortDirection): MockCustomer[] {
  const factor = direction === 'asc' ? 1 : -1;

  return [...rows].sort((a, b) => {
    // No expiry date shows while a balance is negative (see BalanceCell) — treat
    // it as "unknown", always last, regardless of sort direction.
    if (key === 'expires') {
      if (a.expires_at === b.expires_at) return 0;
      if (a.expires_at === null) return 1;
      if (b.expires_at === null) return -1;
      return factor * a.expires_at.localeCompare(b.expires_at);
    }

    return factor * compareByKey(a, b, key);
  });
}

function compareByKey(a: MockCustomer, b: MockCustomer, key: SortKey): number {
  switch (key) {
    case 'name':
      return a.name.localeCompare(b.name);
    case 'tier':
      return a.tier.localeCompare(b.tier);
    case 'balance':
      return a.balance - b.balance;
    case 'pending':
      return a.pending_points - b.pending_points;
    case 'lifetime':
      return a.lifetime_earned - b.lifetime_earned;
    case 'last_activity':
      // Inverted vs. every other numeric column here: `last_activity_minutes_ago`
      // is "time elapsed" (smaller = more recent), but desc is documented above
      // as "most-recent first" — swapping a/b makes desc sort by recency
      // (smallest elapsed first) instead of by the raw elapsed value (which
      // would put the OLDEST activity first under desc — the bug this fixes).
      return b.last_activity_minutes_ago - a.last_activity_minutes_ago;
    case 'referrals':
      return a.referrals - b.referrals;
    default:
      return 0;
  }
}
