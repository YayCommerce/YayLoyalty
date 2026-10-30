import { __ } from '@wordpress/i18n';

import type { CustomerBalanceRange, CustomerExpiringSoon } from '@/lib/schema/customer.schema';

/** Filter option labels for the Customers toolbar; matching runs server-side in CustomerRowFilters.php. */
export type BalanceRangeValue = CustomerBalanceRange;
export type ExpiringSoonValue = CustomerExpiringSoon;

export interface CustomerListFilters {
  search: string;
  balanceRange: BalanceRangeValue;
  expiringSoon: ExpiringSoonValue;
}

export const DEFAULT_CUSTOMER_LIST_FILTERS: CustomerListFilters = {
  search: '',
  balanceRange: 'any',
  expiringSoon: 'off',
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
  { value: 'no_expiry', label: __('No expiry date', 'yayloyalty') },
];

/** Initial filters from the route's search params (the dashboard's deep link); unknown values fall back to defaults. */
export function initialFiltersFromSearch(params: URLSearchParams): CustomerListFilters {
  const pick = <T extends string>(key: string, options: { value: T }[], fallback: T): T => {
    const raw = params.get(key);
    return options.some((o) => o.value === raw) ? (raw as T) : fallback;
  };

  return {
    ...DEFAULT_CUSTOMER_LIST_FILTERS,
    balanceRange: pick('balance_range', BALANCE_RANGE_OPTIONS, DEFAULT_CUSTOMER_LIST_FILTERS.balanceRange),
    expiringSoon: pick('expiring_soon', EXPIRING_SOON_OPTIONS, DEFAULT_CUSTOMER_LIST_FILTERS.expiringSoon),
  };
}
