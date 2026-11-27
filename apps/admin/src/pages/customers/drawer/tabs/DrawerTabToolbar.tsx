import type { ReactNode } from 'react';
import type { DateRange } from 'react-day-picker';

import { ExpandingSearch } from '@/components/ui/custom/expanding-search';
import { Select, SelectContent, SelectTrigger, SelectValue } from '@/components/ui/select';
import { DrawerDateRangeButton } from './DrawerDateRangeButton';
import { DrawerFilterSelectItem } from './DrawerFilterSelectItem';

/** The drawer tabs' one-row filters: a select, a search toggle, then `extra` and a date range button. */
export function DrawerTabToolbar<F extends string>({
  options,
  value,
  onChange,
  ariaLabel,
  range,
  onRangeChange,
  search,
  onSearchChange,
  searchPlaceholder,
  extra,
}: {
  options: { value: F; label: string; count?: number }[];
  value: F;
  onChange: (value: F) => void;
  ariaLabel?: string;
  range?: DateRange | undefined;
  onRangeChange?: (range: DateRange | undefined) => void;
  search: string;
  onSearchChange: (value: string) => void;
  searchPlaceholder: string;
  extra?: ReactNode;
}) {
  return (
    <div className="flex flex-wrap items-center gap-2">
      <Select value={value} onValueChange={(next) => onChange(next as F)}>
        <SelectTrigger className="w-40" aria-label={ariaLabel}>
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          {options.map((option) => (
            <DrawerFilterSelectItem key={option.value} value={option.value} label={option.label} count={option.count} />
          ))}
        </SelectContent>
      </Select>
      <ExpandingSearch value={search} onChange={onSearchChange} placeholder={searchPlaceholder} />
      <div className="ml-auto flex items-center gap-2">
        {extra}
        {onRangeChange && <DrawerDateRangeButton value={range} onValueChange={onRangeChange} />}
      </div>
    </div>
  );
}
