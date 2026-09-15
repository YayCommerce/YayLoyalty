import { __ } from '@wordpress/i18n';

import type { CustomerBalanceRange, CustomerExpiringSoon, CustomerTier } from '@/lib/schema/customer.schema';

/**
 * Search/filter option types + labels for the Customers list toolbar.
 *
 * The actual matching runs server-side, in `CustomerRowFilters.php` (mirrors
 * this file's values field-for-field) via `CustomerRepository::paginate_list()`
 * — see `lib/api/customer.api.ts`. This file only keeps the labels/option
 * arrays the UI needs; the value unions themselves are re-exported from
 * `lib/schema/customer.schema.ts`, since `lib/` code (the API layer) needs
 * them too and must not import from `pages/`.
 */
export type BalanceRangeValue = CustomerBalanceRange;
export type ExpiringSoonValue = CustomerExpiringSoon;
/** 'all' + the tiers the backend hands out. No tiers table exists yet — any non-'all' value matches zero rows (intentional, see CustomerRowFilters.php). Pro-only — see CustomersToolbar.tsx. */
export type TierValue = CustomerTier;

/**
 * Single on/off switch for the Tier filter control, separate from
 * `IS_PRO_BUILD`. The control is real (server-checked) but the data behind it
 * isn't — no `tiers` table exists yet, so any non-"all" value still matches
 * zero rows server-side (`CustomerRowFilters.php`'s `c.tier_id IS NOT NULL`
 * is never true). Back on for UI-checking alongside the sample Tier column
 * (`customers-page.tier-sample-data.ts`) — the two are NOT connected: the
 * column's sample value is derived client-side from `balance` purely for
 * display, while this filter still round-trips to the real, tier-less
 * backend, so picking a tier here empties the list regardless of what the
 * column shows. Flip back to `false` (or wire a real filter) once VIP Tiers
 * ships. Every call site that gates the Tier filter reads this —
 * `CustomersToolbar.tsx` (the control itself), `CustomersPage.tsx` /
 * `CustomersScreen.tsx` (active-filter count feeding "No customers match N
 * filters" / the no-match suggestions).
 */
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
