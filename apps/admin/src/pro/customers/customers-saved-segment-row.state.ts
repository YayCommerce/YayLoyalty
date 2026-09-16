import { useLayoutEffect, useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { toast } from 'sonner';
import { __ } from '@wordpress/i18n';

import { getApiErrorMessage } from '@/lib/api/api';
import { DEFAULT_ROW_FILTERS, type RowFilters } from '@/pages/customers/customers-page.filter-rows';
import { readSegmentsFromLocalize, removeSegment, type SavedSegment } from './customer-segments.api';

/** Reserved `Select` value for the virtual "no segment" default — never stored. */
export const ALL_SEGMENTS_VALUE = 'all';

/** The subset of `RowFilters` a segment actually stores — see `SavedSegment['filters']`. */
type SaveableFilters = SavedSegment['filters'];

function saveableFiltersOf({ balanceRange, expiringSoon, tier }: RowFilters): SaveableFilters {
  return { balanceRange, expiringSoon, tier };
}

/**
 * All the state, derived booleans and mutations behind
 * `CustomersSavedSegmentRow.tsx` — split out (like `CustomersSaveSegmentDialog.tsx`)
 * to keep that file under this repo's 200-line guideline as the feature grew
 * a remove action alongside select/save.
 *
 * Selecting a stored segment applies its filters AND clears the search box
 * in one `onFiltersChange` call: `CustomersPage.updateFilters()` already
 * applies a multi-key patch immediately and resets the page, so
 * `{ ...segment.filters, search: '' }` is the whole job — nothing here
 * debounces or double-fetches.
 *
 * `selected` shows the LAST PICKED segment even after the user changes a
 * filter by hand afterwards — it is a "last applied" indicator, not a live
 * diff against the current filters (KISS: no filter-equality check). ONE
 * exception: once every saveable filter is back at its default (`Clear
 * all`, or hand-clearing each one) there is no active segment by definition,
 * so `selected` itself is reset to "All customers" (the effect below) — not
 * just a derived display value. That distinction matters: without
 * committing the reset into `selected`, a later UNRELATED manual filter
 * change (e.g. Clear all, then picking Tier=Alpine by hand) would make a
 * merely-derived display fall back to the old `selected` again and show a
 * stale, unrelated segment name as if it were still applied.
 *
 * `showSaveButton` needs BOTH of:
 * - at least one of the three saveable filters is non-default — otherwise
 *   there is nothing to save, it would be indistinguishable from "All
 *   customers". `search` is never part of this: a segment never stores it.
 * - the current filters DIFFER from `appliedFilters` — the saveable filters
 *   as they stood right after the last select-a-segment, successful save,
 *   or successful remove. So picking "bbb" hides the button (current now
 *   equals what was just applied); hand-changing a filter afterwards shows
 *   it again.
 * Both checks are needed, not just the second: "Clear all"
 * (`CustomersToolbar.tsx`) resets filters straight to `DEFAULT_ROW_FILTERS`
 * WITHOUT going through `onSelect` (it isn't a segment pick), so
 * `appliedFilters` is left stale at whatever segment was last selected —
 * current-vs-applied alone would then read "different" and wrongly show the
 * button for a fully-default, nothing-to-save state.
 *
 * `showRemoveButton` occupies the SAME slot as `showSaveButton` — exactly
 * one of the two (or neither) is ever true. `!showSaveButton` in its
 * condition is deliberate: once filters have drifted from the selected
 * segment, the relevant action is saving the NEW state (as a new segment),
 * not removing the OLD one, which is untouched and still valid.
 */
export function useSavedSegmentPicker({
  filters,
  onFiltersChange,
}: {
  filters: RowFilters;
  onFiltersChange: (patch: Partial<RowFilters>) => void;
}) {
  const [segments, setSegments] = useState<SavedSegment[]>(() => readSegmentsFromLocalize());
  const [selected, setSelected] = useState(ALL_SEGMENTS_VALUE);
  // The saveable filters as they stood right after the last select, save or
  // remove. Starts at the current filters: on mount nothing has been
  // changed by the user yet, so the Save button starts hidden.
  const [appliedFilters, setAppliedFilters] = useState<SaveableFilters>(() => saveableFiltersOf(filters));
  const [dialogOpen, setDialogOpen] = useState(false);

  const current = saveableFiltersOf(filters);
  const hasSaveableFilters =
    current.balanceRange !== DEFAULT_ROW_FILTERS.balanceRange ||
    current.expiringSoon !== DEFAULT_ROW_FILTERS.expiringSoon ||
    current.tier !== DEFAULT_ROW_FILTERS.tier;
  const differsFromApplied =
    current.balanceRange !== appliedFilters.balanceRange ||
    current.expiringSoon !== appliedFilters.expiringSoon ||
    current.tier !== appliedFilters.tier;
  const showSaveButton = hasSaveableFilters && differsFromApplied;
  const showRemoveButton = selected !== ALL_SEGMENTS_VALUE && !showSaveButton;

  // `useLayoutEffect`, not `useEffect`: this runs before the browser paints,
  // so the trigger never flashes a stale segment name for one frame before
  // snapping to "All customers".
  useLayoutEffect(() => {
    if (!hasSaveableFilters) {
      setSelected(ALL_SEGMENTS_VALUE);
    }
  }, [hasSaveableFilters]);

  function onSelect(value: string) {
    setSelected(value);

    if (value === ALL_SEGMENTS_VALUE) {
      onFiltersChange(DEFAULT_ROW_FILTERS);
      setAppliedFilters(saveableFiltersOf(DEFAULT_ROW_FILTERS));
      return;
    }

    const segment = segments.find((item) => item.name === value);
    if (segment) {
      onFiltersChange({ ...segment.filters, search: '' });
      setAppliedFilters(segment.filters);
    }
  }

  function onSaved(list: SavedSegment[]) {
    setSegments(list);
    // append_segment() only ever appends, so the new segment is always last
    // in the returned list; select and baseline off what the SERVER stored
    // (sanitize_text_field() on the PHP side can alter the typed name).
    const saved = list[list.length - 1];
    if (saved) {
      setSelected(saved.name);
      // The just-saved segment IS the new baseline — nothing left to save
      // until the user changes a filter again.
      setAppliedFilters(saved.filters);
    }
  }

  const remove = useMutation({
    mutationFn: () => removeSegment(selected),
    onSuccess: (list) => {
      setSegments(list);
      // The removed segment can no longer be "selected" — falls back to the
      // virtual default. Live filters are left untouched (removing only
      // affects the saved list, not what the table is currently showing),
      // which is also why the baseline resets to DEFAULT_ROW_FILTERS: if
      // those filters are still non-default, Save reappears so the exact
      // same state can be saved again, e.g. under a different name.
      setSelected(ALL_SEGMENTS_VALUE);
      setAppliedFilters(saveableFiltersOf(DEFAULT_ROW_FILTERS));
    },
    onError: async (err) => {
      toast.error(await getApiErrorMessage(err, __('Could not remove the segment.', 'yayloyalty')));
    },
  });

  return {
    segments,
    selected,
    current,
    showSaveButton,
    showRemoveButton,
    dialogOpen,
    setDialogOpen,
    onSelect,
    onSaved,
    onRemove: () => remove.mutate(),
    isRemoving: remove.isPending,
  };
}
