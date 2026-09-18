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
  { min: 10001, tier: 'Explorer' },
  { min: 1001, tier: 'Summit' },
  { min: 0, tier: 'Alpine' },
];

/** null (renders "—") for a negative balance. */
export function sampleTierForBalance(balance: number): CustomerTier | null {
  return TIER_SAMPLE_THRESHOLDS.find((row) => balance >= row.min)?.tier ?? null;
}

/** Tier → the balance-range bucket it stands in for; same boundaries as TIER_SAMPLE_THRESHOLDS. */
const TIER_TO_BALANCE_RANGE: Record<Exclude<CustomerTier, 'all'>, CustomerBalanceRange> = {
  Alpine: '0-1000',
  Summit: '1000-10000',
  Explorer: '10000-plus',
};

/** What to send as balance_range: the tier's bucket when a tier is picked, else the real filter. */
export function resolveBalanceRangeForQuery(filters: Pick<RowFilters, 'tier' | 'balanceRange'>): CustomerBalanceRange {
  return filters.tier === 'all' ? filters.balanceRange : TIER_TO_BALANCE_RANGE[filters.tier];
}
