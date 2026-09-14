import { __ } from '@wordpress/i18n';

/**
 * Search/filter option types + labels for the Customers list toolbar.
 *
 * The actual matching runs server-side, in `CustomerRowFilters.php` (mirrors
 * this file's values field-for-field), so results are checked against the
 * whole dataset, not just whatever page already loaded. This file only keeps
 * the shapes/labels the UI needs.
 *
 * No real list endpoint calls `CustomerRowFilters` yet — the demo one
 * (`customers-page.api.ts` / `CustomerRestController::get_items()`) was
 * removed ahead of it; wire the real fetch's params to this file's values
 * the same way that demo version did.
 */
export type BalanceRangeValue = 'any' | 'negative' | '0-1000' | '1000-10000' | '10000-plus';
export type ExpiringSoonValue = 'off' | '30' | '90' | 'none';
/** 'all' + the tiers the backend hands out. Pro-only — see CustomersToolbar.tsx. */
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
