import { useLayoutEffect, useState } from 'react';
import { __ } from '@wordpress/i18n';

import { Can, CAP } from '@/lib/access';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { DEFAULT_ROW_FILTERS, type RowFilters } from '@/pages/customers/customers-page.filter-rows';
import { readSegmentsFromLocalize, type SavedSegment } from './customer-segments.api';
import { CustomersSaveSegmentDialog } from './CustomersSaveSegmentDialog';

/** Reserved `Select` value for the virtual "no segment" default — never stored. */
const ALL_SEGMENTS_VALUE = 'all';

/** The subset of `RowFilters` a segment actually stores — see `SavedSegment['filters']`. */
type SaveableFilters = SavedSegment['filters'];

function saveableFiltersOf({ balanceRange, expiringSoon, tier }: RowFilters): SaveableFilters {
  return { balanceRange, expiringSoon, tier };
}

/**
 * Pro-only saved-segment picker shown above the Customers toolbar.
 *
 * DELETED FROM THE LITE BUILD along with the rest of `src/pro/` — see
 * `CustomersFilterBar.tsx`'s `IS_PRO_BUILD` early return, which is what
 * makes this reachable at all.
 *
 * Selecting a stored segment applies its filters AND clears the search box
 * in one `onFiltersChange` call: `CustomersPage.updateFilters()` already
 * applies a multi-key patch immediately and resets the page, so
 * `{ ...segment.filters, search: '' }` is the whole job — nothing here
 * debounces or double-fetches.
 *
 * The dropdown shows the LAST PICKED segment even after the user changes a
 * filter by hand afterwards — it is a "last applied" indicator, not a live
 * diff against the current filters (KISS: no filter-equality check). ONE
 * exception: once every saveable filter is back at its default (`Clear
 * all`, or hand-clearing each one) there is no active segment by definition,
 * so `selected` itself is reset to "All customers" (the effect below) — not
 * just the displayed value. That distinction matters: without committing the
 * reset into `selected`, a later UNRELATED manual filter change (e.g. Clear
 * all, then picking Tier=Alpine by hand) would make a merely-derived display
 * fall back to the old `selected` again and show a stale, unrelated segment
 * name as if it were still applied. This is still not a live diff against
 * any specific segment's filters — just recognising the one state ("nothing
 * is applied") that has an unambiguous right answer.
 *
 * "Save current filters as segment" needs BOTH of:
 * - at least one of the three saveable filters (Balance range / Expiring
 *   soon / Tier) is non-default — otherwise there is nothing to save, it
 *   would be indistinguishable from "All customers". `search` is never
 *   part of this: a segment never stores it.
 * - the current filters DIFFER from `appliedFilters` — the saveable
 *   filters as they stood right after the last select-a-segment or
 *   successful save. So picking "bbb" hides the button (current now
 *   equals what was just applied); hand-changing a filter afterwards shows
 *   it again.
 *
 * Both checks are needed, not just the second: "Clear all"
 * (`CustomersToolbar.tsx`) resets filters straight to `DEFAULT_ROW_FILTERS`
 * WITHOUT going through this component's `handleSelect` (it isn't a
 * segment pick), so `appliedFilters` is left stale at whatever segment was
 * last selected — current-vs-applied alone would then read "different" and
 * wrongly show the button for a fully-default, nothing-to-save state.
 */
export function CustomersSavedSegmentRow({
  disabled,
  filters,
  onFiltersChange,
}: {
  disabled?: boolean;
  filters: RowFilters;
  onFiltersChange: (patch: Partial<RowFilters>) => void;
}) {
  const [segments, setSegments] = useState<SavedSegment[]>(() => readSegmentsFromLocalize());
  const [selected, setSelected] = useState(ALL_SEGMENTS_VALUE);
  // The saveable filters as they stood right after the last select or save —
  // see the class doc above. Starts at the current filters: on mount nothing
  // has been changed by the user yet, so the button starts hidden.
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

  // See the class doc's "ONE exception" — `Clear all` (CustomersToolbar.tsx)
  // resets filters WITHOUT going through handleSelect, so `selected` must be
  // corrected here too, not just derived-and-discarded, or it resurfaces the
  // next time an unrelated manual filter change makes hasSaveableFilters
  // true again. `useLayoutEffect`, not `useEffect`: this runs before the
  // browser paints, so the trigger never flashes the stale segment name for
  // one frame before snapping to "All customers".
  useLayoutEffect(() => {
    if (!hasSaveableFilters) {
      setSelected(ALL_SEGMENTS_VALUE);
    }
  }, [hasSaveableFilters]);

  function handleSelect(value: string) {
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

  function handleSaved(list: SavedSegment[]) {
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

  return (
    <div className="flex flex-wrap items-center gap-2">
      <span className="text-muted-foreground text-xs">{__('Saved segment', 'yayloyalty')}</span>
      <Select value={selected} onValueChange={handleSelect} disabled={disabled}>
        <SelectTrigger size="sm">
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value={ALL_SEGMENTS_VALUE}>{__('All customers', 'yayloyalty')}</SelectItem>
          {segments.map((segment) => (
            <SelectItem key={segment.name} value={segment.name}>
              {segment.name}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>

      {showSaveButton && (
        <Can cap={CAP.MANAGE_POINTS} disable>
          <Button variant="link" size="sm" disabled={disabled} onClick={() => setDialogOpen(true)}>
            {__('Save current filters as segment', 'yayloyalty')}
          </Button>
        </Can>
      )}

      <CustomersSaveSegmentDialog
        open={dialogOpen}
        onOpenChange={setDialogOpen}
        filters={current}
        onSaved={handleSaved}
      />
    </div>
  );
}
