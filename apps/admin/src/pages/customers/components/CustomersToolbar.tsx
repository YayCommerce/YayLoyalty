import { __ } from '@wordpress/i18n';

import { CAP, Can } from '@/lib/access';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import {
  BALANCE_RANGE_OPTIONS,
  EXPIRING_SOON_OPTIONS,
  TIER_OPTIONS,
  type BalanceRangeValue,
  type ExpiringSoonValue,
  type RowFilters,
  type TierValue,
} from '../customers-page.filter-rows';

/**
 * Search + filter row + Export CSV. Every filter is real — see
 * `customers-page.filter-rows.ts` — over the rows already loaded.
 *
 * Free/Pro split is `__IS_PRO__`, directly: the Tier filter simply isn't
 * rendered in a Lite build, matching the Customer list itself (no Tier
 * column, no upgrade badge needed for a control that isn't there).
 */
export function CustomersToolbar({
  filters,
  onFiltersChange,
  /** True store (no members at all) — every control goes inert but stays in
   *  place, so the toolbar doesn't pop in later and shift the table down. */
  disabled,
  exportDisabled,
}: {
  filters: RowFilters;
  onFiltersChange: (patch: Partial<RowFilters>) => void;
  disabled: boolean;
  exportDisabled: boolean;
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
      {__IS_PRO__ && (
        <FilterSelect
          placeholder={__('Tier', 'yayloyalty')}
          value={filters.tier}
          onValueChange={(value) => onFiltersChange({ tier: value as TierValue })}
          options={TIER_OPTIONS}
          disabled={disabled}
        />
      )}
      <Can cap={CAP.VIEW} disable>
        <Button variant="outline" size="sm" className="ml-auto" disabled={disabled || exportDisabled}>
          {__('Export CSV', 'yayloyalty')}
        </Button>
      </Can>
    </div>
  );
}

/** A real, working filter dropdown. */
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
