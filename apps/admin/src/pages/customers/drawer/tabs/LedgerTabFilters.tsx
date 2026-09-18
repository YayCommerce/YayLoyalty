import type { DateRange } from 'react-day-picker';

import { formatPoints } from '@/lib/helpers/points.helper';
import type { LedgerFilterType, LedgerTypeCounts } from '@/lib/schema/ledger.schema';
import { DateRangePicker } from '@/components/ui/date-range-picker';
import { Input } from '@/components/ui/input';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import {
  DATE_RANGE_LABEL,
  FILTER_TYPE_ADJUSTED,
  FILTER_TYPE_ALL,
  FILTER_TYPE_EARNED,
  FILTER_TYPE_EXPIRED,
  FILTER_TYPE_LABEL,
  FILTER_TYPE_REDEEMED,
  FILTER_UNAVAILABLE_REASON,
  SEARCH_PLACEHOLDER,
} from '../customer-drawer.copy';

const TYPE_LABELS: Record<LedgerFilterType, () => string> = {
  all: FILTER_TYPE_ALL,
  earn: FILTER_TYPE_EARNED,
  redeem: FILTER_TYPE_REDEEMED,
  adjust: FILTER_TYPE_ADJUSTED,
  expire: FILTER_TYPE_EXPIRED,
};

/**
 * Two rows, as 3c draws them: the type filter as one segmented control with
 * every per-type count on its own segment, then search (taking the width) with
 * the date range beside it.
 *
 * A segmented control, not a dropdown: the counts are the point of the filter
 * — "4,190 earned, 38 adjusted" tells the admin where to look before they
 * click, and a dropdown hides exactly that behind one collapsed label (3c
 * note 3). It carries `FILTER_TYPE_LABEL` as its accessible name since, unlike
 * the dropdown it replaces, it shows no "Type:" prefix of its own.
 *
 * `typeCounts` is `null` on the real path (Wave A has no type-count query yet)
 * — every control disables and a single line of copy explains why, rather than
 * filters that silently do nothing against real data (worse than no filter UI
 * at all). The segments then render bare labels; nothing prints a fake zero.
 */
export function LedgerTabFilters({
  type,
  onTypeChange,
  typeCounts,
  range,
  onRangeChange,
  search,
  onSearchChange,
  disabled,
}: {
  type: LedgerFilterType;
  onTypeChange: (type: LedgerFilterType) => void;
  typeCounts: LedgerTypeCounts | null;
  range: DateRange | undefined;
  onRangeChange: (range: DateRange | undefined) => void;
  search: string;
  onSearchChange: (value: string) => void;
  disabled: boolean;
}) {
  return (
    <div className="flex flex-col gap-2">
      {/* Scrolling belongs to this wrapper, not the control: `Segmented` is a
          fixed `h-8`, so a classic (non-overlay) scrollbar would eat half the
          track and clip the segments. `w-max` keeps the group hugging its
          segments the way 3c draws it — never a grey track with a dead tail —
          while still growing past the wrapper below the `sm` breakpoint. */}
      <div className="overflow-x-auto">
        <Segmented
          shape="square"
          size="sm"
          aria-label={FILTER_TYPE_LABEL()}
          value={type}
          // A second click on the active segment reports '' (Radix deselect);
          // ignore it — "no type at all" is not a state this filter has.
          onValueChange={(value) => value && onTypeChange(value as LedgerFilterType)}
          disabled={disabled}
          className="w-max"
        >
          {(Object.keys(TYPE_LABELS) as LedgerFilterType[]).map((key) => (
            <SegmentedItem key={key} value={key} className="px-2.5">
              {TYPE_LABELS[key]()}
              {typeCounts && (
                <span className="text-muted-foreground text-xs tabular-nums">{formatPoints(typeCounts[key])}</span>
              )}
            </SegmentedItem>
          ))}
        </Segmented>
      </div>
      <div className="flex items-center gap-2">
        <Input
          className="min-w-0 flex-1"
          placeholder={SEARCH_PLACEHOLDER()}
          value={search}
          onChange={(event) => onSearchChange(event.target.value)}
          disabled={disabled}
        />
        <DateRangePicker
          value={range}
          onValueChange={onRangeChange}
          placeholder={DATE_RANGE_LABEL()}
          disabled={disabled}
          // `min-w-0` defeats `ComboboxTrigger`'s own `min-w-75` (300px), which
          // survives `w-auto` — a different tailwind-merge group. Without it the
          // date control is wider than the search field it sits beside, and on
          // the full-width sheet below `sm` it holds 300px while the input
          // collapses to nothing.
          className="w-auto min-w-0 shrink-0"
        />
      </div>
      {disabled && <p className="text-muted-foreground text-xs">{FILTER_UNAVAILABLE_REASON()}</p>}
    </div>
  );
}
