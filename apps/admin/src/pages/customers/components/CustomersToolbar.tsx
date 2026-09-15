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
 * Search + filter row. Every filter is real — checked server-side, see
 * `lib/api/customer.api.ts` → `CustomerRepository::paginate_list()`.
 * "Clear all" only shows up once search or a filter is actually active
 * (`countActiveFilters`) and resets everything at once — same reset
 * `CustomersNoMatchState`'s "Clear all filters" button uses.
 *
 * Tier is real as a control but not as data: no `tiers` table exists yet, so
 * every customer's tier would be "—" and any non-"all" Tier selection would
 * match zero rows (see `CustomerRowFilters.php`). Hidden behind
 * `TIER_FILTER_ENABLED` (`customers-page.filter-rows.ts`) until VIP Tiers
 * ships — the wiring here is untouched so flipping that flag is all it takes.
 *
 * `exportSlot` is Free-only (see CustomersFilterBar.tsx): 3 filters + Export fit
 * on one row without wrapping, so Free renders Export here. Pro's 4th filter
 * (Tier) means Export has to live in its own row instead, alongside the
 * saved-segment picker — a `ml-auto` button wrapping onto its own line there
 * read as a layout bug.
 *
 * Free/Pro split is `IS_PRO_BUILD`, directly: the Tier filter simply isn't
 * rendered in a Lite build, matching the Customer list itself (no Tier
 * column, no upgrade badge needed for a control that isn't there).
 */
export function CustomersToolbar({
  filters,
  onFiltersChange,
  /** True store (no members at all) or mid-"Simulate rebuild" (see
   *  CustomersScreen.tsx) — every control goes inert but stays in place, so
   *  the toolbar doesn't pop in later and shift the table down. */
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
      {IS_PRO_BUILD && TIER_FILTER_ENABLED && (
        <FilterSelect
          placeholder={__('Tier', 'yayloyalty')}
          value={filters.tier}
          onValueChange={(value) => onFiltersChange({ tier: value as TierValue })}
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
