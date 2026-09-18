import { __ } from '@wordpress/i18n';

import type { CustomerBalanceRange, CustomerExpiringSoon, CustomerTier } from '@/lib/schema/customer.schema';

/** Filter option labels for the Customers toolbar; matching runs server-side in CustomerRowFilters.php. */
export type BalanceRangeValue = CustomerBalanceRange;
export type ExpiringSoonValue = CustomerExpiringSoon;
/** No tiers table yet: any non-'all' value matches zero rows server-side. Pro-only. */
export type TierValue = CustomerTier;

/** On/off switch for the Tier filter control, which stands in on balance buckets until VIP Tiers ships. */
export const TIER_FILTER_ENABLED = true;

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

/** Initial filters from the route's search params (the dashboard's deep link); unknown values fall back to defaults. */
export function initialFiltersFromSearch(params: URLSearchParams): RowFilters {
  const pick = <T extends string>(key: string, options: { value: T }[], fallback: T): T => {
    const raw = params.get(key);
    return options.some((o) => o.value === raw) ? (raw as T) : fallback;
  };

  return {
    ...DEFAULT_ROW_FILTERS,
    balanceRange: pick('balance_range', BALANCE_RANGE_OPTIONS, DEFAULT_ROW_FILTERS.balanceRange),
    expiringSoon: pick('expiring_soon', EXPIRING_SOON_OPTIONS, DEFAULT_ROW_FILTERS.expiringSoon),
  };
}
