import { useState } from 'react';
import { SearchIcon } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { ledgerFilterLabel } from '@/lib/helpers/ledger.helper';
import { formatCount } from '@/lib/helpers/points.helper';
import { LEDGER_FILTER_TYPES, type LedgerFilterType } from '@/lib/schema/ledger.schema';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';

/** Type tabs with a count pill each, and a search that opens from an icon button on the right. */
export function DashboardActivityToolbar({
  filter,
  onFilterChange,
  countOf,
  search,
  onSearchChange,
}: {
  filter: LedgerFilterType;
  onFilterChange: (filter: LedgerFilterType) => void;
  countOf: (filter: LedgerFilterType) => number;
  search: string;
  onSearchChange: (search: string) => void;
}) {
  const [searchOpen, setSearchOpen] = useState(search !== '');
  const placeholder = __('Search customer or event', 'yayloyalty');

  return (
    <div className="flex flex-wrap items-center gap-2">
      <div className="overflow-x-auto">
        <Segmented
          shape="square"
          size="sm"
          aria-label={__('Activity type', 'yayloyalty')}
          value={filter}
          // Radix reports '' on re-clicking the active segment; keep a filter selected.
          onValueChange={(next) => next && onFilterChange(next as LedgerFilterType)}
          className="w-max"
        >
          {LEDGER_FILTER_TYPES.map((f) => (
            <SegmentedItem key={f} value={f} className="px-2.5">
              {ledgerFilterLabel(f)}
              <span
                className={cn(
                  'flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-[11px] leading-none tabular-nums',
                  f === filter ? 'bg-foreground text-background' : 'bg-muted-foreground/15 text-muted-foreground',
                )}
              >
                {formatCount(countOf(f))}
              </span>
            </SegmentedItem>
          ))}
        </Segmented>
      </div>
      <div className="ml-auto">
        {searchOpen ? (
          <Input
            autoFocus
            className="h-8 w-56 min-w-0"
            placeholder={placeholder}
            value={search}
            onChange={(event) => onSearchChange(event.target.value)}
            onBlur={() => search === '' && setSearchOpen(false)}
          />
        ) : (
          <Button variant="outline" size="icon-sm" aria-label={placeholder} onClick={() => setSearchOpen(true)}>
            <SearchIcon />
          </Button>
        )}
      </div>
    </div>
  );
}
