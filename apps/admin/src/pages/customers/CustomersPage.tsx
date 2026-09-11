import { useMemo, useState } from 'react';
import { RefreshCw } from 'lucide-react';
import { sprintf, __ } from '@wordpress/i18n';

import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import PageLayout from '@/components/layout/PageLayout';
import { CustomersScreen } from './components/CustomersScreen';
import { CustomersUpsellFooter } from './components/CustomersUpsellFooter';
import { DEFAULT_ROW_FILTERS, filterRows, type RowFilters } from './customers-page.filter-rows';
import { useCustomersDemoQuery } from './customers-page.queries';

/**
 * Customers list screen — designed UI, driven by mock data fetched over a
 * real REST round trip until the real list endpoint lands (see
 * `customer.queries.ts` — only single-customer / ledger / adjust hooks exist
 * today; `customers-page.queries.ts` is the TEMPORARY stand-in).
 *
 * Owns only the DATA concerns: the query, the filters that shape `rows`, and
 * the "Simulate rebuild" toggle (there is no real background job — see
 * CustomersRebuildBanner.tsx). Sort, pagination and row selection are all
 * VIEW-only state and live in `CustomersScreen.tsx` instead.
 *
 * FREE/PRO SPLIT IS `__IS_PRO__`, DIRECTLY — no demo switcher, no upgrade
 * overlay. Free gets a fully working list (search, sort, filter, export,
 * pagination); Pro adds the Tier/Referrals columns, row selection and the
 * bulk action bar on top of the same list — see `CustomersScreen.tsx`.
 *
 * Search (by name) and the Balance range / Expiring soon / Tier filters ARE
 * real — see `customers-page.filter-rows.ts` — because they only narrow the
 * rows already loaded, unlike Export or the bulk actions, which would need a
 * real dataset behind them to mean anything.
 */
export default function CustomersPage() {
  const [filters, setFilters] = useState<RowFilters>(DEFAULT_ROW_FILTERS);
  const [isRebuilding, setIsRebuilding] = useState(false);

  const customersQuery = useCustomersDemoQuery();
  const allRows = useMemo(() => customersQuery.data ?? [], [customersQuery.data]);

  const filteredRows = useMemo(() => filterRows(allRows, filters), [allRows, filters]);

  function updateFilters(patch: Partial<RowFilters>) {
    setFilters((current) => ({ ...current, ...patch }));
  }

  return (
    <PageLayout
      title={__('Customers', 'yayloyalty')}
      description={
        customersQuery.isPending ? (
          <Skeleton className="h-4 w-32" />
        ) : (
          sprintf(__('%d program members', 'yayloyalty'), allRows.length)
        )
      }
      actions={
        __IS_PRO__ && (
          // Demo-only: there is no real background job to reflect, so this is
          // a plain toggle — not a "Tools" trigger. See CustomersRebuildBanner.tsx.
          <Button variant="outline" size="sm" onClick={() => setIsRebuilding((current) => !current)}>
            <RefreshCw className="size-3.5" />
            {isRebuilding ? __('Stop simulating rebuild', 'yayloyalty') : __('Simulate rebuild', 'yayloyalty')}
          </Button>
        )
      }
    >
      {customersQuery.isError && (
        <p className="text-destructive text-sm">{__('Could not load customers.', 'yayloyalty')}</p>
      )}

      <CustomersScreen
        allRows={allRows}
        rows={filteredRows}
        isLoading={customersQuery.isPending}
        filters={filters}
        onFiltersChange={updateFilters}
        isRebuilding={isRebuilding}
      />

      {!__IS_PRO__ && <CustomersUpsellFooter />}
    </PageLayout>
  );
}
