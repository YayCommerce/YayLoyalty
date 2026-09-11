import { useMemo, useState } from 'react';
import { sprintf, __ } from '@wordpress/i18n';

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
 * FREE/PRO SPLIT IS `__IS_PRO__`, DIRECTLY — no demo switcher, no upgrade
 * overlay. Free gets a fully working list (search, sort, filter, export);
 * Pro adds the Tier/Referrals columns, row selection and the bulk action bar
 * on top of the same list — see `CustomersScreen.tsx`.
 *
 * Search (by name) and the Balance range / Expiring soon filters ARE real —
 * see `customers-page.filter-rows.ts` — because they only narrow the rows
 * already loaded, unlike Export or the bulk actions, which would need a real
 * dataset behind them to mean anything.
 */
export default function CustomersPage() {
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [filters, setFilters] = useState<RowFilters>(DEFAULT_ROW_FILTERS);

  const customersQuery = useCustomersDemoQuery();
  const allRows = useMemo(() => customersQuery.data ?? [], [customersQuery.data]);

  const filteredRows = useMemo(() => filterRows(allRows, filters), [allRows, filters]);

  const headerSelection = useMemo(() => {
    if (selected.size === 0) return 'none' as const;
    if (selected.size >= filteredRows.length && filteredRows.length > 0) return 'all' as const;
    return 'partial' as const;
  }, [selected, filteredRows.length]);

  function toggleRow(userId: number) {
    setSelected((current) => {
      const next = new Set(current);
      if (next.has(userId)) {
        next.delete(userId);
      } else {
        next.add(userId);
      }
      return next;
    });
  }

  function toggleAll() {
    // "Select all" acts on the rows currently visible (post search/filter).
    setSelected((current) => (current.size > 0 ? new Set() : new Set(filteredRows.map((row) => row.user_id))));
  }

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
        selected={selected}
        headerSelection={headerSelection}
        onToggleAll={toggleAll}
        onToggleRow={toggleRow}
        onClearSelection={() => setSelected(new Set())}
      />

      {!__IS_PRO__ && <CustomersUpsellFooter />}
    </PageLayout>
  );
}
