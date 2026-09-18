import type { DateRange } from 'react-day-picker';

import { formatPoints } from '@/lib/helpers/points.helper';
import type { LedgerFilterType, LedgerTypeCounts } from '@/lib/schema/ledger.schema';
import { DateRangePicker } from '@/components/ui/date-range-picker';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
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
 * Type + per-type counts, date range, search. `typeCounts` is `null` on the
 * real path (Wave A has no type-count query yet) — every control disables
 * and a single line of copy explains why, rather than filters that silently
 * do nothing against real data (worse than no filter UI at all).
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
      <div className="flex flex-wrap items-center gap-2">
        <Select value={type} onValueChange={(value) => onTypeChange(value as LedgerFilterType)} disabled={disabled}>
          <SelectTrigger size="sm">
            <span className="text-muted-foreground">{FILTER_TYPE_LABEL()}:</span>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {(Object.keys(TYPE_LABELS) as LedgerFilterType[]).map((key) => (
              <SelectItem key={key} value={key}>
                {typeCounts ? `${TYPE_LABELS[key]()} ${formatPoints(typeCounts[key])}` : TYPE_LABELS[key]()}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        <DateRangePicker
          value={range}
          onValueChange={onRangeChange}
          placeholder={DATE_RANGE_LABEL()}
          disabled={disabled}
          className="w-auto"
        />
        <Input
          className="w-70"
          placeholder={SEARCH_PLACEHOLDER()}
          value={search}
          onChange={(event) => onSearchChange(event.target.value)}
          disabled={disabled}
        />
      </div>
      {disabled && <p className="text-muted-foreground text-xs">{FILTER_UNAVAILABLE_REASON()}</p>}
    </div>
  );
}
