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
import { DrawerFiltersCollapse } from './DrawerFiltersCollapse';

const TYPE_LABELS: Record<LedgerFilterType, () => string> = {
  all: FILTER_TYPE_ALL,
  earn: FILTER_TYPE_EARNED,
  redeem: FILTER_TYPE_REDEEMED,
  adjust: FILTER_TYPE_ADJUSTED,
  expire: FILTER_TYPE_EXPIRED,
};

/**
 * Search full width, then the type filter (a segmented control with every
 * per-type count on its own segment) and the date range together — 3c's
 * original two-row layout at `sm` and above.
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
 *
 * Below `sm` (5c callout 2, phase 07): the type control and date range
 * collapse into `DrawerFiltersCollapse`'s single "Filters (N)" trigger;
 * search is the explicit exception and stays outside, full width, at every
 * width. This is the one deliberate visual change from the shipped desktop
 * layout — at `sm` and above, the date range now sits beside the type
 * control inside the (always-inline-there) collapse wrapper rather than
 * beside search as before. Documented in phase-07's Deviations; unverified
 * beyond reading the classes (no browser tool in this environment).
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
  // Non-default state only — search isn't counted here, it's already visible
  // on its own (counting it would double-report the same active filter).
  const activeCount = (type !== 'all' ? 1 : 0) + (range?.from ? 1 : 0);

  return (
    <div>
      <DrawerFiltersCollapse activeCount={activeCount} disabled={disabled}>
        {/* Scrolling belongs to this wrapper, not the control: `Segmented` is a
            fixed `h-8`, so a classic (non-overlay) scrollbar would eat half the
            track and clip the segments. `w-max` keeps the group hugging its
            segments the way 3c draws it — never a grey track with a dead tail. */}
        <div className="flex flex-col gap-2">
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
              // `min-w-0` defeats `ComboboxTrigger`'s own `min-w-75` (300px),
              // which survives `w-auto` — a different tailwind-merge group.
              className="w-auto min-w-0 shrink-0"
            />
          </div>
        </div>
      </DrawerFiltersCollapse>
      {disabled && <p className="text-muted-foreground text-xs">{FILTER_UNAVAILABLE_REASON()}</p>}
    </div>
  );
}
