import { useState, type ReactNode } from 'react';
import { SearchIcon } from 'lucide-react';
import type { DateRange } from 'react-day-picker';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectTrigger, SelectValue } from '@/components/ui/select';
import { DrawerDateRangeButton } from './DrawerDateRangeButton';
import { DrawerFilterSelectItem } from './DrawerFilterSelectItem';

/** The one-row filter layout of DrawerTabFilters: a select, a search toggle, then `extra` and a date range button. */
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
  // A search that is already set stays open, so the filter never hides behind the toggle.
  const [searchOpen, setSearchOpen] = useState(search !== '');

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
      {searchOpen ? (
        <Input
          autoFocus
          className="w-48 min-w-0"
          placeholder={searchPlaceholder}
          value={search}
          onChange={(event) => onSearchChange(event.target.value)}
          onBlur={() => search === '' && setSearchOpen(false)}
        />
      ) : (
        <Button variant="outline" size="icon" aria-label={searchPlaceholder} onClick={() => setSearchOpen(true)}>
          <SearchIcon />
        </Button>
      )}
      <div className="ml-auto flex items-center gap-2">
        {extra}
        {onRangeChange && <DrawerDateRangeButton value={range} onValueChange={onRangeChange} />}
      </div>
    </div>
  );
}
