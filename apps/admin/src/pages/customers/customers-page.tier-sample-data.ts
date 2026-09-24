import type { CustomerBalanceRange, CustomerTier } from '@/lib/schema/customer.schema';
import type { RowFilters } from './customers-page.filter-rows';

/**
 * Temporary UI-check sample data until a tiers table exists. The Tier
 * column derives a tier from balance for display. The Tier filter
 * substitutes the equivalent balance_range bucket (a `tier` param matches
 * nothing server-side), so totals and pagination stay right; the toolbar
 * keeps the two mutually exclusive. Delete with its call sites once VIP Tiers ships.
 */
const TIER_SAMPLE_THRESHOLDS: { min: number; tier: CustomerTier }[] = [
  { min: 10001, tier: 'Alpine' },
  { min: 1001, tier: 'Summit' },
  { min: 0, tier: 'Explorer' },
];

/** null (renders "—") for a negative balance. */
export function sampleTierForBalance(balance: number): CustomerTier | null {
  return TIER_SAMPLE_THRESHOLDS.find((row) => balance >= row.min)?.tier ?? null;
}

/**
 * Same stand-in, extended with "how many points to the next tier" for the
 * customer drawer's tier card — a stand-in for the same reason as this
 * whole file: there is no real per-customer tier to show yet. `next` is
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
 * already checked `tier !== 'all'` (see `toServerFilters`).
 */
const TIER_TO_BALANCE_RANGE: Record<Exclude<CustomerTier, 'all'>, CustomerBalanceRange> = {
  Explorer: '0-1000',
  Summit: '1000-10000',
  Alpine: '10000-plus',
};

/**
 * The filters as every customers request must send them: a Tier pick goes
 * out as its balance_range bucket and `tier` as 'all', since a real `tier`
 * param matches nothing server-side. The list, the bulk walk and the
 * adjust preview all route through this so they count the same rows.
 */
export function toServerFilters(filters: RowFilters): RowFilters & { tier: 'all' } {
  const balanceRange: CustomerBalanceRange =
    filters.tier === 'all' ? filters.balanceRange : TIER_TO_BALANCE_RANGE[filters.tier];

  return { ...filters, balanceRange, tier: 'all' };
}
