import { useState } from 'react';
import { ListFilter, Search } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { Button } from '@/components/ui/button';
import { InputGroup, InputGroupAddon, InputGroupInput } from '@/components/ui/input-group';
import { CustomersSavedSegmentRow, CustomersTierFilter } from '@/pro';
import { countActiveFilters } from '../customers-page.filter-suggestions';
import { DEFAULT_CUSTOMER_LIST_FILTERS, type CustomerListFilters } from '../customers-page.filters';
import type { SortDirection, SortKey } from '../customers-page.sort';
import { CustomersExportButton } from './CustomersExportButton';
import { CustomersFilterPanel } from './CustomersFilterPanel';

/**
 * Segment picker (Pro) on the left; search, Export and the Filter toggle on the right; the filter panel below.
 * The IS_PRO_BUILD ternaries are also the guard that keeps the @/pro imports out of the Lite bundle.
 */
export function CustomersFilterBar({
  disabled,
  exportDisabled,
  filters,
  onFiltersChange,
  queryFilters,
  sort,
  showTiers,
}: {
  disabled: boolean;
  exportDisabled: boolean;
  filters: CustomerListFilters;
  onFiltersChange: (patch: Partial<CustomerListFilters>) => void;
  /** Committed filters, what Export should fetch. */
  queryFilters: CustomerListFilters;
  sort: { by: SortKey; dir: SortDirection };
  /** Pro with tiers on: the Tier filter shows in the panel. */
  showTiers: boolean;
}) {
  // Search lives in the bar, so it is not counted on the Filter button.
  const panelCount = countActiveFilters({ ...filters, search: '' });
  // Opens on its own when filters arrive already set, such as the dashboard's deep link.
  const [panelOpen, setPanelOpen] = useState(panelCount > 0);

  return (
    <>
      <div className="flex flex-wrap items-center justify-between gap-3">
        {IS_PRO_BUILD ? (
          <CustomersSavedSegmentRow disabled={disabled} filters={filters} onFiltersChange={onFiltersChange} />
        ) : (
          <span />
        )}
        <div className="flex flex-wrap items-center gap-2">
          <InputGroup className="w-60">
            <InputGroupAddon>
              <Search className="size-4" />
            </InputGroupAddon>
            <InputGroupInput
              placeholder={__('Search', 'yayloyalty')}
              aria-label={__('Search name or email', 'yayloyalty')}
              value={filters.search}
              onChange={(event) => onFiltersChange({ search: event.target.value })}
              disabled={disabled}
            />
          </InputGroup>
          <CustomersExportButton
            disabled={exportDisabled}
            queryFilters={queryFilters}
            sort={sort}
            className="bg-foreground text-background hover:bg-foreground/85 hover:text-background h-9 border-transparent px-4"
          />
          <Button
            variant="outline"
            aria-expanded={panelOpen}
            disabled={disabled}
            onClick={() => setPanelOpen((open) => !open)}
          >
            <ListFilter />
            {__('Filter', 'yayloyalty')}
            {panelCount > 0 && (
              <span className="bg-foreground text-background flex size-4.5 items-center justify-center rounded-sm text-[11px] tabular-nums">
                {panelCount}
              </span>
            )}
          </Button>
        </div>
      </div>

      {panelOpen && (
        <CustomersFilterPanel
          filters={filters}
          onFiltersChange={onFiltersChange}
          disabled={disabled}
          extraFilters={
            IS_PRO_BUILD && showTiers ? (
              <CustomersTierFilter
                value={filters.tier}
                onChange={(tier) => onFiltersChange({ tier })}
                disabled={disabled}
              />
            ) : undefined
          }
          onClear={
            panelCount > 0
              ? () => onFiltersChange({ ...DEFAULT_CUSTOMER_LIST_FILTERS, search: filters.search })
              : undefined
          }
        />
      )}
    </>
  );
}
