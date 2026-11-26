import { ReactNode } from 'react';
import { __ } from '@wordpress/i18n';

import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import {
  BALANCE_RANGE_OPTIONS,
  EXPIRING_SOON_OPTIONS,
  type BalanceRangeValue,
  type CustomerListFilters,
  type ExpiringSoonValue,
} from '../customers-page.filters';

/**
 * The filters the Filter button opens, each labelled above its control.
 * `extraFilters` sits after the built-in filters; Pro puts the Tier filter there.
 */
export function CustomersFilterPanel({
  filters,
  onFiltersChange,
  /** Empty store: controls go inert but stay in place, so the table does not shift. */
  disabled,
  extraFilters,
  onClear,
}: {
  filters: CustomerListFilters;
  onFiltersChange: (patch: Partial<CustomerListFilters>) => void;
  disabled: boolean;
  extraFilters?: ReactNode;
  /** Present while any panel filter is set. */
  onClear?: () => void;
}) {
  return (
    <div className="bg-muted/30 flex flex-col gap-3 rounded-lg border border-dashed p-4">
      <div className="grid gap-4 sm:grid-cols-[repeat(auto-fit,minmax(200px,1fr))]">
        <LabeledFilter label={__('Balance range', 'yayloyalty')}>
          <FilterSelect
            label={__('Balance range', 'yayloyalty')}
            value={filters.balanceRange}
            onValueChange={(value) => onFiltersChange({ balanceRange: value as BalanceRangeValue })}
            options={BALANCE_RANGE_OPTIONS}
            disabled={disabled}
          />
        </LabeledFilter>
        <LabeledFilter label={__('Expiring soon', 'yayloyalty')}>
          <FilterSelect
            label={__('Expiring soon', 'yayloyalty')}
            value={filters.expiringSoon}
            onValueChange={(value) => onFiltersChange({ expiringSoon: value as ExpiringSoonValue })}
            options={EXPIRING_SOON_OPTIONS}
            disabled={disabled}
          />
        </LabeledFilter>
        {extraFilters && <LabeledFilter label={__('Tier', 'yayloyalty')}>{extraFilters}</LabeledFilter>}
      </div>
      {onClear && (
        <Button variant="link" size="sm" className="self-end" disabled={disabled} onClick={onClear}>
          {__('Clear all', 'yayloyalty')}
        </Button>
      )}
    </div>
  );
}

function LabeledFilter({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex min-w-0 flex-col gap-1.5">
      <span className="text-muted-foreground text-xs">{label}</span>
      {children}
    </div>
  );
}

function FilterSelect<T extends string>({
  label,
  value,
  onValueChange,
  options,
  disabled,
}: {
  label: string;
  value: T;
  onValueChange: (value: T) => void;
  options: { value: T; label: string }[];
  disabled?: boolean;
}) {
  return (
    <Select value={value} onValueChange={onValueChange} disabled={disabled}>
      <SelectTrigger className="w-full" aria-label={label}>
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
