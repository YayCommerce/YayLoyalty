import { _n, sprintf, __ } from '@wordpress/i18n';

import { BALANCE_RANGE_OPTIONS, DEFAULT_ROW_FILTERS, EXPIRING_SOON_OPTIONS, type RowFilters } from './customers-page.filter-rows';

/**
 * "Which single filter should I drop?" — the counts come from the server,
 * folded into the same list response once the current filters combine to
 * zero results (see `CustomerRestController::get_items()` /
 * `CustomerRowFilters::suggestions_for_no_match()`), not from a second round
 * trip per filter. Only present once 2+ filters are active — with a single
 * filter, the only candidate is "no filters at all", not worth suggesting
 * over "Clear all". This file only turns those raw counts into display text.
 */
export type FilterKey = 'search' | 'balanceRange' | 'expiringSoon' | 'tier';

/** A raw `{key, count}` pair as the server sends it — see `customers-page.api.ts`. */
export interface NoMatchSuggestion {
  key: FilterKey;
  count: number;
}

export interface FilterDropSuggestion extends NoMatchSuggestion {
  label: string;
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

/**
 * Number of filters currently narrowing the list — drives the "No customers
 * match all N filters" title, and gates the suggestion list itself (2+
 * only — see the class doc above; mirrored server-side so the two never
 * disagree about when a suggestion is worth showing).
 */
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
 * Turns the server's raw `{key, count}` pairs into display-ready
 * suggestions — pure and synchronous: the label/phrasing text is built from
 * `filters` (the same state the toolbar renders), not sent over the wire, so
 * nothing gets translated on both sides of the connection.
 */
export function toFilterDropSuggestions(raw: NoMatchSuggestion[], filters: RowFilters): FilterDropSuggestion[] {
  return raw.map(({ key, count }) => ({
    key,
    count,
    label: describeFilterValue(key, filters),
    phrasing: key === 'search' ? ('only' as const) : ('without' as const),
  }));
}

export function countLabel(count: number): string {
  return sprintf(_n('%d customer', '%d customers', count, 'yayloyalty'), count);
}

/**
 * Mirrors how the server built the candidate it counted (see
 * `CustomerRowFilters::suggestions_for_no_match()`): 'without' reverts just
 * that one filter; 'only' (search) reverts every OTHER filter and keeps the
 * typed search term.
 */
export function patchForSuggestion(suggestion: FilterDropSuggestion, filters: RowFilters): Partial<RowFilters> {
  if (suggestion.phrasing === 'only') {
    return { ...DEFAULT_ROW_FILTERS, search: filters.search };
  }

  return { [suggestion.key]: DEFAULT_ROW_FILTERS[suggestion.key] };
}
