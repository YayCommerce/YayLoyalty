import { ReactNode } from 'react';
import { __ } from '@wordpress/i18n';

import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import {
  BALANCE_RANGE_OPTIONS,
  DEFAULT_ROW_FILTERS,
  EXPIRING_SOON_OPTIONS,
  TIER_FILTER_ENABLED,
  TIER_OPTIONS,
  type BalanceRangeValue,
  type ExpiringSoonValue,
  type RowFilters,
  type TierValue,
} from '../customers-page.filter-rows';
import { countActiveFilters } from '../customers-page.filter-suggestions';

/**
 * Search and filter row. Tier and Balance range are mutually exclusive: both drive the one balance_range param.
 * `exportSlot` is Lite-only. In Pro, Export moves to the segment row.
 */
export function CustomersToolbar({
  filters,
  onFiltersChange,
  /** Empty store: controls go inert but stay in place, so the table does not shift. */
  disabled,
  exportSlot,
}: {
  filters: RowFilters;
  onFiltersChange: (patch: Partial<RowFilters>) => void;
  disabled: boolean;
  exportSlot?: ReactNode;
}) {
  return (
    <div className="flex flex-wrap items-center gap-2">
      <Input
        className="w-70"
        placeholder={__('Search name or email', 'yayloyalty')}
        value={filters.search}
        onChange={(event) => onFiltersChange({ search: event.target.value })}
        disabled={disabled}
      />
      <FilterSelect
        placeholder={__('Balance range', 'yayloyalty')}
        value={filters.balanceRange}
        onValueChange={(value) => onFiltersChange({ balanceRange: value as BalanceRangeValue, tier: 'all' })}
        options={BALANCE_RANGE_OPTIONS}
        disabled={disabled}
      />
      <FilterSelect
        placeholder={__('Expiring soon', 'yayloyalty')}
        value={filters.expiringSoon}
        onValueChange={(value) => onFiltersChange({ expiringSoon: value as ExpiringSoonValue })}
        options={EXPIRING_SOON_OPTIONS}
        disabled={disabled}
      />
      {IS_PRO_BUILD && TIER_FILTER_ENABLED && (
        <FilterSelect
          placeholder={__('Tier', 'yayloyalty')}
          value={filters.tier}
          onValueChange={(value) => onFiltersChange({ tier: value as TierValue, balanceRange: 'any' })}
          options={TIER_OPTIONS}
          disabled={disabled}
        />
      )}
      {countActiveFilters(filters, IS_PRO_BUILD && TIER_FILTER_ENABLED) > 0 && (
        <Button variant="link" size="sm" disabled={disabled} onClick={() => onFiltersChange(DEFAULT_ROW_FILTERS)}>
          {__('Clear all', 'yayloyalty')}
        </Button>
      )}
      {exportSlot && <div className="ml-auto">{exportSlot}</div>}
    </div>
  );
}

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
