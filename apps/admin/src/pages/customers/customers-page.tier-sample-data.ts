import type { CustomerBalanceRange, CustomerTier } from '@/lib/schema/customer.schema';
import type { RowFilters } from './customers-page.filter-rows';

/**
 * Sample tiers until a tiers table exists. The Tier column derives a tier from balance.
 * The Tier filter sends the matching balance_range bucket instead, since a `tier` param matches nothing server-side.
 * TODO(vip-tiers): delete this file and its call sites once tiers ship.
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
 * The sample tier plus the points to the next one, for the drawer's tier card.
 * `next` is null at the top tier (Alpine). The whole result is null for a negative balance.
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

/** Each tier's balance_range bucket, with the same boundaries as TIER_SAMPLE_THRESHOLDS and `CustomerRowFilters::balance_bounds()`. */
const TIER_TO_BALANCE_RANGE: Record<Exclude<CustomerTier, 'all'>, CustomerBalanceRange> = {
  Explorer: '0-1000',
  Summit: '1000-10000',
  Alpine: '10000-plus',
};

/**
 * The filters as every customers request must send them: a Tier pick goes out as its balance_range bucket, with `tier` as 'all'.
 * The list, the bulk walk and the adjust preview all use this, so they count the same rows.
 */
export function toServerFilters(filters: RowFilters): RowFilters & { tier: 'all' } {
  const balanceRange: CustomerBalanceRange =
    filters.tier === 'all' ? filters.balanceRange : TIER_TO_BALANCE_RANGE[filters.tier];

  return { ...filters, balanceRange, tier: 'all' };
}
