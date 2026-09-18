import type { DateRange } from 'react-day-picker';

import type { ActivityGroup } from '@/lib/schema/activity.schema';
import { DateRangePicker } from '@/components/ui/date-range-picker';
import { Input } from '@/components/ui/input';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import {
  ACTIVITY_GROUP_ADMIN,
  ACTIVITY_GROUP_ALL,
  ACTIVITY_GROUP_EMAILS,
  ACTIVITY_GROUP_EXPIRY,
  ACTIVITY_GROUP_TIER,
  ACTIVITY_SEARCH_PLACEHOLDER,
  DATE_RANGE_LABEL,
} from '../customer-drawer.copy';

const GROUP_LABELS: Record<ActivityGroup, () => string> = {
  all: ACTIVITY_GROUP_ALL,
  admin: ACTIVITY_GROUP_ADMIN,
  tier: ACTIVITY_GROUP_TIER,
  expiry: ACTIVITY_GROUP_EXPIRY,
  emails: ACTIVITY_GROUP_EMAILS,
};

/**
 * `Show:` group + search + date range — same two-row `LedgerTabFilters.tsx`
 * composition (Wave A phase 06's post-ship notes), now that Activity log
 * paginates instead of accumulating (see `ActivityLogTab.tsx`'s docblock).
 * Unlike the ledger's type filter, groups carry no per-group counts on
 * their segments — 3d draws this filter with plain labels only — and
 * nothing here ever disables: the real path's `0` total is genuinely true
 * whatever the filters are set to, not a "server can't filter yet" signal
 * (contrast the ledger's `typeCounts === null` disabled state).
 */
export function ActivityLogTabFilters({
  group,
  onGroupChange,
  range,
  onRangeChange,
  search,
  onSearchChange,
}: {
  group: ActivityGroup;
  onGroupChange: (group: ActivityGroup) => void;
  range: DateRange | undefined;
  onRangeChange: (range: DateRange | undefined) => void;
  search: string;
  onSearchChange: (value: string) => void;
}) {
  return (
    <div className="flex flex-col gap-2">
      {/* Scrolling belongs to this wrapper, not `Segmented` itself — see
          `LedgerTabFilters.tsx`'s identical comment for why. */}
      <div className="overflow-x-auto">
        <Segmented
          shape="square"
          size="sm"
          value={group}
          onValueChange={(value) => value && onGroupChange(value as ActivityGroup)}
          className="w-max"
        >
          {(Object.keys(GROUP_LABELS) as ActivityGroup[]).map((key) => (
            <SegmentedItem key={key} value={key} className="px-2.5">
              {GROUP_LABELS[key]()}
            </SegmentedItem>
          ))}
        </Segmented>
      </div>
      <div className="flex items-center gap-2">
        <Input
          className="min-w-0 flex-1"
          placeholder={ACTIVITY_SEARCH_PLACEHOLDER()}
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
