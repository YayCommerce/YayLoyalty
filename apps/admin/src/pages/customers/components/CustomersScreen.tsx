import { Card, CardContent } from '@/components/ui/card';
import type { RowFilters } from '../customers-page.filter-rows';
import type { MockCustomer } from '../customers-page.mock-data';
import type { ScenarioConfig } from '../customers-page.scenario-types';
import { CustomersApproxCountBanner } from './CustomersApproxCountBanner';
import { CustomersBulkActionBar } from './CustomersBulkActionBar';
import { CustomersEmptyState } from './CustomersEmptyState';
import { CustomersInfoPanels } from './CustomersInfoPanels';
import { CustomersPagination } from './CustomersPagination';
import { CustomersRebuildBanner } from './CustomersRebuildBanner';
import { CustomersSavedSegmentRow } from './CustomersSavedSegmentRow';
import { CustomersSuggestions } from './CustomersSuggestions';
import { CustomersTable } from './CustomersTable';
import { CustomersToolbar } from './CustomersToolbar';

type HeaderSelectionState = 'none' | 'partial' | 'all';

/**
 * The full screen body for one scenario: rebuild banner → saved segment row →
 * toolbar → approx-count banner → bulk action bar → table → pagination →
 * info panels. Every bracketed piece is conditional on the scenario's config.
 * Split out of `CustomersPage.tsx` to keep the page file under 200 lines.
 */
export function CustomersScreen({
  config,
  rows,
  filters,
  onFiltersChange,
  selected,
  headerSelection,
  onToggleAll,
  onToggleRow,
  onClearSelection,
}: {
  config: ScenarioConfig;
  /** Already search/filter-narrowed by the page — see CustomersPage.tsx. */
  rows: MockCustomer[];
  filters: RowFilters;
  onFiltersChange: (patch: Partial<RowFilters>) => void;
  selected: Set<number>;
  headerSelection: HeaderSelectionState;
  onToggleAll: () => void;
  onToggleRow: (userId: number) => void;
  onClearSelection: () => void;
}) {
  const showBulkBar = config.bulkBar && selected.size > 0;

  return (
    <Card>
      <CardContent className="flex flex-col gap-4">
        {config.rebuild && <CustomersRebuildBanner {...config.rebuild} />}
        {config.savedSegment && <CustomersSavedSegmentRow {...config.savedSegment} />}

        <CustomersToolbar toolbar={config.toolbar} filters={filters} onFiltersChange={onFiltersChange} />

        {config.approxCount && <CustomersApproxCountBanner {...config.approxCount} />}

        {showBulkBar && config.bulkBar && (
          <CustomersBulkActionBar
            selectedLabel={config.bulkBar.selectedLabel}
            coversPageNote={config.bulkBar.coversPageNote}
            selectAllLabel={config.bulkBar.selectAllLabel}
            onClear={onClearSelection}
          />
        )}

        <CustomersTable
          // Remounts on scenario change so each demo state opens on its own
          // default sort (Balance ▼) rather than carrying over a click made
          // in a previously-viewed scenario.
          key={config.id}
          rows={rows}
          body={config.body}
          showProColumns={config.showProColumns}
          showSelection={config.showProColumns}
          headerSelection={headerSelection}
          onToggleAll={onToggleAll}
          selected={selected}
          onToggleRow={onToggleRow}
          staleBalances={config.staleBalances}
          toolbarInert={config.toolbar.mode === 'inert'}
          emptyBlock={
            config.emptyCopy && (
              <CustomersEmptyState title={config.emptyCopy.title} description={config.emptyCopy.description} links={config.emptyCopy.links}>
                {config.suggestions && <CustomersSuggestions suggestions={config.suggestions} />}
              </CustomersEmptyState>
            )
          }
        />

        <CustomersPagination
          rangeLabel={config.pagination.rangeLabel}
          note={config.pagination.note}
          previousDisabled={config.pagination.previousDisabled}
          nextDisabled={config.pagination.nextDisabled}
          loading={config.body === 'loading'}
        />

        {config.infoPanels && <CustomersInfoPanels panels={config.infoPanels} />}
      </CardContent>
    </Card>
  );
}
