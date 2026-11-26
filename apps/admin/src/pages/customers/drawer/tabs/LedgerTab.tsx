import { ledgerTypeLabel } from '@/lib/helpers/ledger.helper';
import { useCustomerLedgerQuery } from '@/lib/queries/customer.queries';
import type { Customer } from '@/lib/schema/customer.schema';
import { LEDGER_FILTER_TYPES, type LedgerFilterType } from '@/lib/schema/ledger.schema';
import { useLedgerCampaignNames } from '../customer-drawer.campaign-names';
import { FILTER_TYPE_ALL, FILTER_TYPE_LABEL, SEARCH_PLACEHOLDER } from '../customer-drawer.copy';
import { toLedgerRow } from '../customer-drawer.ledger-rows';
import { useDrawerTabQueryState } from '../customer-drawer.tab-query-state';
import { CustomerDrawerActions } from '../CustomerDrawerActions';
import { DRAWER_PAGE_SIZE, DrawerTabPager } from './DrawerTabPager';
import { DrawerTabToolbar } from './DrawerTabToolbar';
import { LedgerTabRows } from './LedgerTabRows';

const TYPE_LABELS: Record<LedgerFilterType, () => string> = {
  all: FILTER_TYPE_ALL,
  earn: () => ledgerTypeLabel('earn'),
  redeem: () => ledgerTypeLabel('redeem'),
  adjust: () => ledgerTypeLabel('adjust'),
  expire: () => ledgerTypeLabel('expire'),
};

/**
 * Toolbar, table and pager. Each type option shows its count, so the admin sees where to look before picking.
 * The row that caused a negative balance is marked.
 */
export function LedgerTab({ customer, customerFirstName }: { customer: Customer; customerFirstName: string }) {
  const userId = customer.user_id;
  const causeLedgerId = customer.negative_balance?.cause_ledger_id ?? null;
  const state = useDrawerTabQueryState<LedgerFilterType>('all');
  const query = useCustomerLedgerQuery(userId, { ...state.pageParams, type: state.filter });

  const campaignNames = useLedgerCampaignNames();
  const rows = query.data?.data.map((entry) => toLedgerRow(entry, campaignNames, causeLedgerId)) ?? [];
  const totalItems = query.data?.totalItems ?? 0;
  // The counts describe the unfiltered set.
  const typeCounts = query.data?.type_counts;

  return (
    <div className="flex flex-col gap-3">
      <DrawerTabToolbar
        options={LEDGER_FILTER_TYPES.map((type) => ({
          value: type,
          label: TYPE_LABELS[type](),
          count: typeCounts?.[type],
        }))}
        value={state.filter}
        onChange={state.setFilter}
        ariaLabel={FILTER_TYPE_LABEL()}
        range={state.range}
        onRangeChange={state.setRange}
        search={state.search}
        onSearchChange={state.setSearch}
        searchPlaceholder={SEARCH_PLACEHOLDER()}
        extra={<CustomerDrawerActions customer={customer} />}
      />
      <LedgerTabRows
        rows={rows}
        isLoading={query.isPending}
        isFiltered={state.isFiltered}
        customerFirstName={customerFirstName}
      />
      <DrawerTabPager
        page={state.page}
        perPage={DRAWER_PAGE_SIZE}
        totalItems={totalItems}
        onPageChange={state.setPage}
        disabled={query.isFetching}
        isPending={query.isPending}
      />
    </div>
  );
}
