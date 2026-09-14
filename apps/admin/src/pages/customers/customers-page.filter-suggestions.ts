import { _n, sprintf, __ } from '@wordpress/i18n';

import { getCustomersDemo } from './customers-page.api';
import { BALANCE_RANGE_OPTIONS, DEFAULT_ROW_FILTERS, EXPIRING_SOON_OPTIONS, type RowFilters } from './customers-page.filter-rows';

/**
 * "Which single filter should I drop?" — computed against the whole (demo)
 * dataset, not just whatever page happened to load, when the active filters
 * combine to zero results (artboard 4a). Real counts, not canned copy: each
 * suggestion re-queries the server with exactly one filter reverted to its
 * default and reads back `totalItems` — `per_page: 1` keeps each check to a
 * single row over the wire, since only the count is needed.
 */
export type FilterKey = 'search' | 'balanceRange' | 'expiringSoon' | 'tier';

export interface FilterDropSuggestion {
  key: FilterKey;
  label: string;
  count: number;
  /**
   * 'without' — drop just this filter, keep the rest ("Without Tier: Alpine").
   * 'only' — drop every OTHER filter, keep just this one ("Search "x" on its
   * own"). Search gets 'only': a typed name is usually the intent, and the
   * other filters are what's fighting it — "Without the search" would keep
   * exactly the filters causing the zero result and is rarely useful.
   */
  phrasing: 'without' | 'only';
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
 * One suggestion per currently-active filter. Search is isolated (every
 * other filter dropped); every other key is relaxed (just that one filter
 * dropped, the rest kept) — see `FilterDropSuggestion.phrasing`. Suggestions
 * that still return nothing are omitted — a "Use this" that stays empty
 * helps no one.
 */
export async function getFilterDropSuggestions(filters: RowFilters, includeTier: boolean): Promise<FilterDropSuggestion[]> {
  const suggestions = await Promise.all(
    activeFilterKeys(filters, includeTier).map(async (key) => {
      const phrasing = key === 'search' ? ('only' as const) : ('without' as const);
      const candidate: RowFilters =
        phrasing === 'only' ? { ...DEFAULT_ROW_FILTERS, search: filters.search } : { ...filters, [key]: DEFAULT_ROW_FILTERS[key] };

      const { totalItems } = await getCustomersDemo({ ...candidate, per_page: 1 });

      return {
        key,
        label: describeFilterValue(key, filters),
        count: totalItems,
        phrasing,
      };
    }),
  );

  return suggestions.filter((suggestion) => suggestion.count > 0).sort((a, b) => b.count - a.count);
}

export function countLabel(count: number): string {
  return sprintf(_n('%d customer', '%d customers', count, 'yayloyalty'), count);
}

/**
 * Mirrors how `getFilterDropSuggestions` built the candidate it counted:
 * 'without' reverts just that one filter; 'only' (search) reverts every
 * OTHER filter and keeps the typed search term.
 */
export function patchForSuggestion(suggestion: FilterDropSuggestion, filters: RowFilters): Partial<RowFilters> {
  if (suggestion.phrasing === 'only') {
    return { ...DEFAULT_ROW_FILTERS, search: filters.search };
  }

  return { [suggestion.key]: DEFAULT_ROW_FILTERS[suggestion.key] };
}
