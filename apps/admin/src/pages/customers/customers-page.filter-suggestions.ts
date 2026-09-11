import { _n, sprintf, __ } from '@wordpress/i18n';

import {
  BALANCE_RANGE_OPTIONS,
  DEFAULT_ROW_FILTERS,
  EXPIRING_SOON_OPTIONS,
  filterRows,
  type RowFilters,
} from './customers-page.filter-rows';
import type { MockCustomer } from './customers-page.mock-data';

/**
 * "Which single filter should I drop?" — computed live against the currently
 * loaded rows when the active filters combine to zero results (artboard 4a).
 * Real counts, not canned copy: each suggestion re-runs `filterRows` with
 * exactly one filter reverted to its default.
 */
export type FilterKey = 'search' | 'balanceRange' | 'expiringSoon' | 'tier';

export interface FilterDropSuggestion {
  key: FilterKey;
  label: string;
  count: number;
}

function activeFilterKeys(filters: RowFilters, includeTier: boolean): FilterKey[] {
  const keys: FilterKey[] = [];
  if (filters.search.trim() !== '') keys.push('search');
  if (filters.balanceRange !== 'any') keys.push('balanceRange');
  if (filters.expiringSoon !== 'off') keys.push('expiringSoon');
  if (includeTier && filters.tier !== 'all') keys.push('tier');
  return keys;
}

/** Number of filters currently narrowing the list — drives the "No customers match all N filters" title. */
export function countActiveFilters(filters: RowFilters, includeTier: boolean): number {
  return activeFilterKeys(filters, includeTier).length;
}

function describeFilterValue(key: FilterKey, filters: RowFilters): string {
  switch (key) {
    case 'search':
      return sprintf(__('Search "%s"', 'yayloyalty'), filters.search.trim());
    case 'balanceRange':
      return BALANCE_RANGE_OPTIONS.find((option) => option.value === filters.balanceRange)?.label ?? '';
    case 'expiringSoon':
      return EXPIRING_SOON_OPTIONS.find((option) => option.value === filters.expiringSoon)?.label ?? '';
    case 'tier':
      return sprintf(__('Tier: %s', 'yayloyalty'), filters.tier);
  }
}

/**
 * One suggestion per currently-active filter, each showing what dropping
 * just that filter (and no other) would yield. Filters that still return
 * nothing on their own are omitted — a "Use this" that stays empty helps no one.
 */
export function getFilterDropSuggestions(
  allRows: MockCustomer[],
  filters: RowFilters,
  includeTier: boolean,
): FilterDropSuggestion[] {
  const suggestions = activeFilterKeys(filters, includeTier).map((key) => {
    const relaxed: RowFilters = { ...filters, [key]: DEFAULT_ROW_FILTERS[key] };
    const count = filterRows(allRows, relaxed).length;

    return {
      key,
      label: describeFilterValue(key, filters),
      count,
    };
  });

  return suggestions.filter((suggestion) => suggestion.count > 0).sort((a, b) => b.count - a.count);
}

export function countLabel(count: number): string {
  return sprintf(_n('%d customer', '%d customers', count, 'yayloyalty'), count);
}
