import type { CustomerBalanceRange, CustomerTier } from '@/lib/schema/customer.schema';
import type { RowFilters } from './customers-page.filter-rows';

/**
 * TEMPORARY, UI-CHECK-ONLY SAMPLE DATA — not a real feature.
 *
 * No `tiers` table exists yet, so the real `GET /customers` response never
 * carries a tier and `CustomerListRow` has no `tier` field to read. The Tier
 * COLUMN (`sampleTierForBalance`, used by `CustomerTableRow.tsx`) derives a
 * plausible-looking one straight from `balance`, purely for display.
 *
 * The Tier FILTER reuses the same idea, but as a real, working filter rather
 * than a display-only stand-in: any non-'all' `tier` wire param always
 * matches zero rows server-side (`CustomerRowFilters.php`'s
 * `c.tier_id IS NOT NULL` is never true), so instead of sending one, this
 * substitutes the REAL, already-working `balance_range` filter for the
 * equivalent bucket — same SQL, so pagination/totals/no-match suggestions
 * stay exactly right, unlike a client-side filter over one already-fetched
 * page (which can't know the true total or fetch "the next matching page").
 * `resolveBalanceRangeForQuery()` below is that substitution; `CustomersPage.tsx`
 * and `customers-page.matching-customers.ts` (bulk Export/Adjust) both call it
 * instead of sending `filters.tier`/`filters.balanceRange` raw. Because both
 * ultimately drive the one `balance_range` wire param, `CustomersToolbar.tsx`
 * keeps them mutually exclusive in the UI — picking one resets the other to
 * its default, so what's shown selected always matches what's actually sent.
 *
 * Edit the tables below to try a different distribution. Delete this whole
 * file (and its three call sites — `CustomerTableRow.tsx`, `CustomersPage.tsx`,
 * `customers-page.matching-customers.ts`) once VIP Tiers ships a real
 * per-customer tier and its own real filter from the server.
 */
const TIER_SAMPLE_THRESHOLDS: { min: number; tier: CustomerTier }[] = [
  { min: 10001, tier: 'Explorer' },
  { min: 1001, tier: 'Summit' },
  { min: 0, tier: 'Alpine' },
];

/** `null` (renders "—") for a negative balance — no earned status to show. */
export function sampleTierForBalance(balance: number): CustomerTier | null {
  return TIER_SAMPLE_THRESHOLDS.find((row) => balance >= row.min)?.tier ?? null;
}

/**
 * Same stand-in, extended with "how many points to the next tier" for the
 * customer drawer's tier card (F09 — added after Wave A shipped without one;
 * the plan's locked scope excluded a *real* tier card because there was
 * nothing real to show, same reasoning as this whole file). `next` is
 * `null` at the top tier (Explorer) — the card shows a standing "top tier
 * reached" state there, not a progress figure. `null` overall for a
 * negative balance, same as `sampleTierForBalance`.
 */
export function sampleTierProgress(
  balance: number,
): { tier: CustomerTier; next: CustomerTier | null; pointsToNext: number | null } | null {
  const ascending = [...TIER_SAMPLE_THRESHOLDS].sort((a, b) => a.min - b.min);
  const index = ascending.findLastIndex((row) => balance >= row.min);

  if (index === -1) return null;

  const next = ascending[index + 1] ?? null;

  return {
    tier: ascending[index].tier,
    next: next?.tier ?? null,
    pointsToNext: next ? next.min - balance : null,
  };
}

/**
 * Tier → the real balance-range bucket it stands in for — same boundaries as
 * `TIER_SAMPLE_THRESHOLDS` above and `CustomerRowFilters::balance_bounds()`.
 * 'all' has no bucket of its own; callers only look this up once they've
 * already checked `tier !== 'all'` (see `resolveBalanceRangeForQuery`).
 */
const TIER_TO_BALANCE_RANGE: Record<Exclude<CustomerTier, 'all'>, CustomerBalanceRange> = {
  Alpine: '0-1000',
  Summit: '1000-10000',
  Explorer: '10000-plus',
};

/**
 * What to actually send as `balance_range` for the current filters: the
 * Tier substitution when a tier is picked, otherwise whatever the real
 * Balance range filter is set to. The two are mutually exclusive (enforced
 * in `CustomersToolbar.tsx`), so this never has to pick between two active
 * values — at most one of `filters.tier`/`filters.balanceRange` is ever
 * non-default at a time.
 */
export function resolveBalanceRangeForQuery(filters: Pick<RowFilters, 'tier' | 'balanceRange'>): CustomerBalanceRange {
  return filters.tier === 'all' ? filters.balanceRange : TIER_TO_BALANCE_RANGE[filters.tier];
}
