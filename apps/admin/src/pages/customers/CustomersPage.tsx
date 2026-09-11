import { useEffect, useMemo, useState } from 'react';
import { __ } from '@wordpress/i18n';

import { Skeleton } from '@/components/ui/skeleton';
import PageLayout from '@/components/layout/PageLayout';
import { ProGate } from '@/components/pro/ProGate';
import { CustomersScreen } from './components/CustomersScreen';
import { CustomersUpsellFooter } from './components/CustomersUpsellFooter';
import { ScenarioSwitcher } from './components/ScenarioSwitcher';
import { DEFAULT_ROW_FILTERS, filterRows, type RowFilters } from './customers-page.filter-rows';
import { CUSTOMERS_SCENARIOS, type CustomersScenarioId } from './customers-page.scenarios';

/**
 * Customers list screen — designed UI, driven entirely by mock data.
 *
 * MOCK-DRIVEN BY DESIGN, NOT AN OVERSIGHT. There is no Customers list REST
 * endpoint yet (only single-customer / ledger / adjust exist in
 * `customer.queries.ts`, still used by the real wiring task, not this file).
 * The on-page `ScenarioSwitcher` browses the 7 designed states without a
 * backend. When the list endpoint lands: add `useCustomersQuery`, delete the
 * switcher and every `customers-page.*` module, and wire the `<Can>`-gated
 * buttons already in place (`CustomersBulkActionBar`, `CustomersToolbar`)
 * to real handlers.
 *
 * Search (by name) and the Balance range / Expiring soon filters ARE real —
 * see `customers-page.filter-rows.ts` — because they only narrow the rows
 * already loaded for the current scenario, unlike Export or the bulk actions,
 * which would need a real dataset behind them to mean anything.
 */
export default function CustomersPage() {
  const [scenarioId, setScenarioId] = useState<CustomersScenarioId>('free-list');
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [filters, setFilters] = useState<RowFilters>(DEFAULT_ROW_FILTERS);

  const config = CUSTOMERS_SCENARIOS[scenarioId];

  const filteredRows = useMemo(() => filterRows(config.rows, filters), [config.rows, filters]);

  // Selection and filters both reset on scenario change: selection is seeded
  // from the scenario's design intent (`config.selection`), not a live count.
  useEffect(() => {
    setFilters(DEFAULT_ROW_FILTERS);

    if (config.selection === 'page-all') {
      setSelected(new Set(config.rows.map((row) => row.user_id)));
    } else if (config.selection === 'page-partial') {
      setSelected(new Set(config.rows.filter((row) => row.selected).map((row) => row.user_id)));
    } else {
      setSelected(new Set());
    }
  }, [config]);

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
    // "Select all" acts on the rows currently visible (post search/filter),
    // never the scenario's full row set.
    setSelected((current) => (current.size > 0 ? new Set() : new Set(filteredRows.map((row) => row.user_id))));
  }

  function updateFilters(patch: Partial<RowFilters>) {
    setFilters((current) => ({ ...current, ...patch }));
  }

  const screen = (
    <CustomersScreen
      config={config}
      rows={filteredRows}
      filters={filters}
      onFiltersChange={updateFilters}
      selected={selected}
      headerSelection={headerSelection}
      onToggleAll={toggleAll}
      onToggleRow={toggleRow}
      onClearSelection={() => setSelected(new Set())}
    />
  );

  return (
    <PageLayout
      title={__('Customers', 'yayloyalty')}
      description={config.memberCount ?? <Skeleton className="h-4 w-32" />}
    >
      <ScenarioSwitcher value={scenarioId} onChange={setScenarioId} />

      {config.isPro ? (
        // Pro lock wraps the whole surface for a Pro-only scenario: Lite gets
        // the upgrade overlay instead of a broken/empty Pro screen.
        <ProGate>{__IS_PRO__ ? screen : null}</ProGate>
      ) : (
        screen
      )}

      {/* Lite-only, not `<ProGate>`: the non-inline overlay renders in Lite and
          children in Pro — the exact inverse of a footer that should show only
          in Lite. See CustomersUpsellFooter's docblock. */}
      {!__IS_PRO__ && config.upsellFooter && <CustomersUpsellFooter />}
    </PageLayout>
  );
}
