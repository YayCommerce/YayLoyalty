import { useLayoutEffect, useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { toast } from 'sonner';
import { __ } from '@wordpress/i18n';

import { getApiErrorMessage } from '@/lib/api/api';
import { DEFAULT_ROW_FILTERS, type RowFilters } from '@/pages/customers/customers-page.filter-rows';
import { readSegmentsFromLocalize, removeSegment, type SavedSegment } from './customer-segments.api';

/** The virtual "no segment" default; never stored. */
export const ALL_SEGMENTS_VALUE = 'all';

type SaveableFilters = SavedSegment['filters'];

function saveableFiltersOf({ balanceRange, expiringSoon, tier }: RowFilters): SaveableFilters {
  return { balanceRange, expiringSoon, tier };
}

/**
 * State and mutations behind CustomersSavedSegmentRow. A hand-made filter
 * change always drops `selected` back to "All customers", so
 * `appliedFilters` is derived from `selected`, never tracked. Save shows
 * when a saveable filter is non-default and differs from what was applied;
 * Remove takes the same slot and shows only when Save does not, since
 * drifted filters call for saving the new state, not removing the old.
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

  // useLayoutEffect so the stale segment name never flashes for a frame.
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
    // Select what the server stored: sanitize_text_field() can alter the typed name.
    const saved = list[list.length - 1];
    if (saved) {
      setSelected(saved.name);
    }
  }

  const remove = useMutation({
    mutationFn: () => removeSegment(selected),
    onSuccess: (list) => {
      setSegments(list);
      // Live filters stay, so the same state can be saved again under another name.
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
