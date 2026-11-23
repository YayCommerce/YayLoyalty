import { ReactNode } from 'react';
import { __ } from '@wordpress/i18n';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { countActiveFilters } from '../customers-page.filter-suggestions';
import {
  BALANCE_RANGE_OPTIONS,
  DEFAULT_CUSTOMER_LIST_FILTERS,
  EXPIRING_SOON_OPTIONS,
  type BalanceRangeValue,
  type CustomerListFilters,
  type ExpiringSoonValue,
} from '../customers-page.filters';

/**
 * Search and filter row.
 * `exportSlot` is Lite-only. In Pro, Export moves to the segment row.
 * `extraFilters` sits after the built-in filters; Pro puts the Tier filter there.
 */
export function CustomersToolbar({
  filters,
  onFiltersChange,
  /** Empty store: controls go inert but stay in place, so the table does not shift. */
  disabled,
  exportSlot,
  extraFilters,
}: {
  filters: CustomerListFilters;
  onFiltersChange: (patch: Partial<CustomerListFilters>) => void;
  disabled: boolean;
  exportSlot?: ReactNode;
  extraFilters?: ReactNode;
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
        onValueChange={(value) => onFiltersChange({ balanceRange: value as BalanceRangeValue })}
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
      {extraFilters}
      {countActiveFilters(filters) > 0 && (
        <Button
          variant="link"
          size="sm"
          disabled={disabled}
          onClick={() => onFiltersChange(DEFAULT_CUSTOMER_LIST_FILTERS)}
        >
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
