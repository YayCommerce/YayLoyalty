import { __ } from '@wordpress/i18n';

import type { MockCustomer } from './customers-page.mock-data';

/**
 * Real, client-side search + filter over the currently-loaded mock rows —
 * same reasoning as `customers-page.sort-rows.ts`: narrowing the rows already
 * on the page is honest UI-only behaviour, unlike pretending to query a
 * larger dataset that doesn't exist here.
 */
export type BalanceRangeValue = 'any' | 'negative' | '0-1000' | '1000-10000' | '10000-plus';
export type ExpiringSoonValue = 'off' | '30' | '90' | 'none';
/** 'all' + the tiers `CustomerDemoRows.php` actually hands out. Pro-only — see CustomersToolbar.tsx. */
export type TierValue = 'all' | 'Alpine' | 'Summit' | 'Explorer';

export interface RowFilters {
  search: string;
  balanceRange: BalanceRangeValue;
  expiringSoon: ExpiringSoonValue;
  tier: TierValue;
}

export const DEFAULT_ROW_FILTERS: RowFilters = {
  search: '',
  balanceRange: 'any',
  expiringSoon: 'off',
  tier: 'all',
};

export const BALANCE_RANGE_OPTIONS: { value: BalanceRangeValue; label: string }[] = [
  { value: 'any', label: __('Any balance', 'yayloyalty') },
  { value: 'negative', label: __('Negative', 'yayloyalty') },
  { value: '0-1000', label: __('0 – 1,000', 'yayloyalty') },
  { value: '1000-10000', label: __('1,000 – 10,000', 'yayloyalty') },
  { value: '10000-plus', label: __('10,000+', 'yayloyalty') },
];

export const EXPIRING_SOON_OPTIONS: { value: ExpiringSoonValue; label: string }[] = [
  { value: 'off', label: __('Off', 'yayloyalty') },
  { value: '30', label: __('Within 30 days', 'yayloyalty') },
  { value: '90', label: __('Within 90 days', 'yayloyalty') },
  { value: 'none', label: __('No expiry date', 'yayloyalty') },
];

export const TIER_OPTIONS: { value: TierValue; label: string }[] = [
  { value: 'all', label: __('All tiers', 'yayloyalty') },
  { value: 'Alpine', label: __('Alpine', 'yayloyalty') },
  { value: 'Summit', label: __('Summit', 'yayloyalty') },
  { value: 'Explorer', label: __('Explorer', 'yayloyalty') },
];

export function filterRows(rows: MockCustomer[], filters: RowFilters): MockCustomer[] {
  const query = filters.search.trim().toLowerCase();
  const now = Date.now();

  return rows.filter(
    (row) =>
      matchesSearch(row, query) &&
      matchesBalanceRange(row.balance, filters.balanceRange) &&
      matchesExpiringSoon(row.expires_at, filters.expiringSoon, now) &&
      matchesTier(row.tier, filters.tier),
  );
}

function matchesSearch(row: MockCustomer, query: string): boolean {
  return query === '' || row.name.toLowerCase().includes(query);
}

function matchesBalanceRange(balance: number, range: BalanceRangeValue): boolean {
  switch (range) {
    case 'any':
      return true;
    case 'negative':
      return balance < 0;
    case '0-1000':
      return balance >= 0 && balance <= 1000;
    case '1000-10000':
      return balance > 1000 && balance <= 10000;
    case '10000-plus':
      return balance > 10000;
  }
}

/** Days until `expires_at`, evaluated against `now` — negative-balance rows carry no expiry (see BalanceCell). */
function matchesExpiringSoon(expiresAt: string | null, option: ExpiringSoonValue, now: number): boolean {
  if (option === 'off') return true;
  if (option === 'none') return expiresAt === null;
  if (expiresAt === null) return false;

  const daysUntil = (new Date(expiresAt.replace(' ', 'T')).getTime() - now) / (1000 * 60 * 60 * 24);
  const withinDays = Number(option);
  return daysUntil >= 0 && daysUntil <= withinDays;
}

function matchesTier(tier: string, filterTier: TierValue): boolean {
  return filterTier === 'all' || tier === filterTier;
}
