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
 * Standard workflow: changing a filter by hand ALWAYS drops `selected` back
 * to "All customers", immediately — there is no "sticky last applied
 * segment" display. `appliedFilters` is therefore DERIVED from `selected`
 * (the picked segment's filters, or `DEFAULT_ROW_FILTERS` for "All
 * customers") rather than tracked as its own state, so it can never go
 * stale relative to what's actually selected. This also covers "Clear all"
 * (`CustomersToolbar.tsx`), which resets filters straight to
 * `DEFAULT_ROW_FILTERS` WITHOUT going through `onSelect` — it still counts
 * as "changed a filter by hand" and drops `selected` to "All customers"
 * exactly like any other manual change.
 *
 * `showSaveButton` needs BOTH of:
 * - at least one of the three saveable filters is non-default — otherwise
 *   there is nothing to save, it would be indistinguishable from "All
 *   customers". `search` is never part of this: a segment never stores it.
 * - the current filters DIFFER from `appliedFilters` — the selected
 *   segment's filters right after the last select, successful save, or
 *   successful remove. So picking "bbb" hides the button (current now
 *   equals what was just applied); hand-changing a filter afterwards shows
 *   it again (and, per the effect below, also drops `selected`).
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
  const [dialogOpen, setDialogOpen] = useState(false);

  const current = saveableFiltersOf(filters);
  // Derived, never its own state: the selected segment's filters, or the
  // defaults for "All customers". Always in sync with `selected` — see the
  // hook doc comment for why this replaced a separately-tracked baseline.
  const selectedSegment = segments.find((item) => item.name === selected);
  const appliedFilters: SaveableFilters =
    selected === ALL_SEGMENTS_VALUE
      ? saveableFiltersOf(DEFAULT_ROW_FILTERS)
      : (selectedSegment?.filters ?? saveableFiltersOf(DEFAULT_ROW_FILTERS));
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
  // so a hand-made filter change never flashes the stale segment name for
  // one frame before snapping to "All customers".
  useLayoutEffect(() => {
    if (selected !== ALL_SEGMENTS_VALUE && differsFromApplied) {
      setSelected(ALL_SEGMENTS_VALUE);
    }
  }, [selected, differsFromApplied]);

  function onSelect(value: string) {
    setSelected(value);

    if (value === ALL_SEGMENTS_VALUE) {
      onFiltersChange(DEFAULT_ROW_FILTERS);
      return;
    }

    const segment = segments.find((item) => item.name === value);
    if (segment) {
      onFiltersChange({ ...segment.filters, search: '' });
    }
  }

  function onSaved(list: SavedSegment[]) {
    setSegments(list);
    // append_segment() only ever appends, so the new segment is always last
    // in the returned list; select off what the SERVER stored
    // (sanitize_text_field() on the PHP side can alter the typed name).
    const saved = list[list.length - 1];
    if (saved) {
      setSelected(saved.name);
    }
  }

  const remove = useMutation({
    mutationFn: () => removeSegment(selected),
    onSuccess: (list) => {
      setSegments(list);
      // The removed segment can no longer be "selected" — falls back to the
      // virtual default. Live filters are left untouched (removing only
      // affects the saved list, not what the table is currently showing), so
      // if those filters are still non-default, Save reappears so the exact
      // same state can be saved again, e.g. under a different name.
      setSelected(ALL_SEGMENTS_VALUE);
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
