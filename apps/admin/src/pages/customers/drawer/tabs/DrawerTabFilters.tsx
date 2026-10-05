import type { DateRange } from 'react-day-picker';

import { formatPoints } from '@/lib/helpers/points.helper';
import { DateRangePicker } from '@/components/ui/date-range-picker';
import { Input } from '@/components/ui/input';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { DATE_RANGE_LABEL } from '../customer-drawer.copy';

/**
 * The filter row shared by the paged, filterable tabs: a segmented control
 * (optionally with a per-option count on each segment), then search and a
 * date range together.
 */
export function DrawerTabFilters<F extends string>({
  options,
  value,
  onChange,
  ariaLabel,
  range,
  onRangeChange,
  search,
  onSearchChange,
  searchPlaceholder,
}: {
  options: { value: F; label: string; count?: number }[];
  value: F;
  onChange: (value: F) => void;
  ariaLabel?: string;
  range: DateRange | undefined;
  onRangeChange: (range: DateRange | undefined) => void;
  search: string;
  onSearchChange: (value: string) => void;
  searchPlaceholder: string;
}) {
  return (
    <div className="flex flex-col gap-2">
      {/* Scrolling belongs to this wrapper, not `Segmented`: it is a fixed
          `h-8`, so a classic scrollbar would eat half the track and clip the
          segments. `w-max` keeps the group hugging its segments. */}
      <div className="overflow-x-auto">
        <Segmented
          shape="square"
          size="sm"
          aria-label={ariaLabel}
          value={value}
          // A second click on the active segment reports '' (Radix deselect);
          // ignore it — "no filter at all" is not a state this control has.
          onValueChange={(next) => next && onChange(next as F)}
          className="w-max"
        >
          {options.map((option) => (
            <SegmentedItem key={option.value} value={option.value} className="px-2.5">
              {option.label}
              {option.count !== undefined && (
                <span className="text-muted-foreground text-xs tabular-nums">{formatPoints(option.count)}</span>
              )}
            </SegmentedItem>
          ))}
        </Segmented>
      </div>
      <div className="flex items-center gap-2">
        <Input
          className="min-w-0 flex-1"
          placeholder={searchPlaceholder}
          value={search}
          onChange={(event) => onSearchChange(event.target.value)}
        />
        <DateRangePicker
          value={range}
          onValueChange={onRangeChange}
          placeholder={DATE_RANGE_LABEL()}
          className="w-auto min-w-0 shrink-0"
        />
      </div>
    </div>
  );
}
