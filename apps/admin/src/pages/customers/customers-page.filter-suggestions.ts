import { __, _n, sprintf } from '@wordpress/i18n';

import type { NoMatchSuggestion } from '@/lib/schema/customer.schema';
import {
  BALANCE_RANGE_OPTIONS,
  DEFAULT_ROW_FILTERS,
  EXPIRING_SOON_OPTIONS,
  type RowFilters,
} from './customers-page.filter-rows';

/**
 * "Which single filter should I drop?" The counts arrive in the list
 * response once filters match nothing; this file only turns them into text.
 */
export type FilterKey = 'search' | 'balanceRange' | 'expiringSoon' | 'tier';

/** Wire param name → RowFilters key; the one place the two spellings meet. */
const FILTER_KEY_BY_WIRE_KEY: Record<NoMatchSuggestion['key'], FilterKey> = {
  search: 'search',
  balance_range: 'balanceRange',
  expiring_soon: 'expiringSoon',
  tier: 'tier',
};

export interface FilterDropSuggestion {
  key: FilterKey;
  count: number;
  label: string;
  /** 'without' drops this filter; 'only' (search) drops every other, since a typed name is usually the intent. */
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

/** Filters currently narrowing the list; suggestions show at 2+, mirrored server-side. */
export function countActiveFilters(filters: RowFilters, includeTier: boolean): number {
  return activeFilterKeys(filters, includeTier).length;
}

function describeFilterValue(key: FilterKey, filters: RowFilters): string {
  switch (key) {
    case 'search':
      return sprintf(
        /* translators: %s: the search term. */
        __('Search "%s"', 'yayloyalty'),
        filters.search.trim(),
      );
    case 'balanceRange':
      return BALANCE_RANGE_OPTIONS.find((option) => option.value === filters.balanceRange)?.label ?? '';
    case 'expiringSoon':
      return EXPIRING_SOON_OPTIONS.find((option) => option.value === filters.expiringSoon)?.label ?? '';
    case 'tier':
      return sprintf(
        /* translators: %s: the tier name. */
        __('Tier: %s', 'yayloyalty'),
        filters.tier,
      );
  }
}

/**
 * Turns the server's {key, count} pairs into display-ready suggestions.
 * A balance_range suggestion is relabelled as tier when a tier is what is
 * active: the server only ever sees the tier's equivalent bucket.
 */
export function toFilterDropSuggestions(raw: NoMatchSuggestion[], filters: RowFilters): FilterDropSuggestion[] {
  return raw.map(({ key: wireKey, count }) => {
    const filterKey = FILTER_KEY_BY_WIRE_KEY[wireKey];
    const key = filterKey === 'balanceRange' && filters.tier !== 'all' ? 'tier' : filterKey;

    return {
      key,
      count,
      label: describeFilterValue(key, filters),
      phrasing: key === 'search' ? ('only' as const) : ('without' as const),
    };
  });
}

export function countLabel(count: number): string {
  return sprintf(
    /* translators: %d: number of customers. */
    _n('%d customer', '%d customers', count, 'yayloyalty'),
    count,
  );
}

/** The filter patch matching the candidate the server counted (CustomerRowFilters::candidates_for_no_match()). */
export function patchForSuggestion(suggestion: FilterDropSuggestion, filters: RowFilters): Partial<RowFilters> {
  if (suggestion.phrasing === 'only') {
    return { ...DEFAULT_ROW_FILTERS, search: filters.search };
  }

  return { [suggestion.key]: DEFAULT_ROW_FILTERS[suggestion.key] };
}
