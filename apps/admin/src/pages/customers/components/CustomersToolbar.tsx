import { ChevronDown } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { CAP, Can } from '@/lib/access';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import {
  BALANCE_RANGE_OPTIONS,
  EXPIRING_SOON_OPTIONS,
  type BalanceRangeValue,
  type ExpiringSoonValue,
  type RowFilters,
} from '../customers-page.filter-rows';
import type { ToolbarConfig } from '../customers-page.scenario-types';
import { CustomersFilterChips } from './CustomersFilterChips';

/**
 * Search + filter row + Export CSV. Three modes driven by `ScenarioConfig.toolbar`:
 * `idle` (default controls, real search + Balance range/Expiring soon filters
 * over the rows already loaded), `chips` (active filters as removable chips,
 * 4a — a fixed illustrative state, left static), `inert` (same controls, all
 * disabled — 2d/4b).
 *
 * Tier and Negative-balance stay visual-only: no scenario currently needs
 * them wired, and there's no larger dataset behind them to narrow honestly.
 */
export function CustomersToolbar({
  toolbar,
  filters,
  onFiltersChange,
}: {
  toolbar: ToolbarConfig;
  filters: RowFilters;
  onFiltersChange: (patch: Partial<RowFilters>) => void;
}) {
  const inert = toolbar.mode === 'inert';

  if (toolbar.mode === 'chips') {
    return <CustomersFilterChips searchValue={toolbar.searchValue ?? ''} chips={toolbar.chips ?? []} exportDisabled />;
  }

  return (
    <div className="flex flex-wrap items-center gap-2">
      <Input
        className="w-70"
        placeholder={__('Search name or email', 'yayloyalty')}
        disabled={inert}
        value={filters.search}
        onChange={(event) => onFiltersChange({ search: event.target.value })}
      />
      <FilterSelect
        placeholder={__('Balance range', 'yayloyalty')}
        value={filters.balanceRange}
        onValueChange={(value) => onFiltersChange({ balanceRange: value as BalanceRangeValue })}
        options={BALANCE_RANGE_OPTIONS}
        disabled={inert}
      />
      <FilterSelect
        placeholder={__('Expiring soon', 'yayloyalty')}
        value={filters.expiringSoon}
        onValueChange={(value) => onFiltersChange({ expiringSoon: value as ExpiringSoonValue })}
        options={EXPIRING_SOON_OPTIONS}
        disabled={inert}
      />
      {toolbar.showNegativeFilter && <FilterButton label={__('Negative balance: off', 'yayloyalty')} disabled={inert} />}
      {toolbar.showTierFilter && <FilterButton label={__('Tier: all', 'yayloyalty')} disabled={inert} />}
      <Can cap={CAP.VIEW} disable>
        <Button variant="outline" size="sm" className="ml-auto" disabled={inert || toolbar.exportDisabled}>
          {__('Export CSV', 'yayloyalty')}
        </Button>
      </Can>
    </div>
  );
}

/** A real, working filter dropdown — unlike `FilterButton` below. */
function FilterSelect<T extends string>({
  placeholder,
  value,
  onValueChange,
  options,
  disabled,
}: {
  placeholder: string;
  value: T;
  onValueChange: (value: T) => void;
  options: { value: T; label: string }[];
  disabled?: boolean;
}) {
  return (
    <Select value={value} onValueChange={onValueChange} disabled={disabled}>
      <SelectTrigger size="sm">
        <span className="text-muted-foreground">{placeholder}:</span>
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        {options.map((option) => (
          <SelectItem key={option.value} value={option.value}>
            {option.label}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}

function FilterButton({ label, disabled }: { label: string; disabled?: boolean }) {
  // UI only — opens no real dropdown; the caret communicates "this will filter" for the mockup.
  return (
    <Button variant="outline" size="sm" disabled={disabled}>
      {label}
      <ChevronDown />
    </Button>
  );
}
