import { useCustomerLedgerQuery } from '@/lib/queries/customer.queries';
import { LEDGER_FILTER_TYPES, type LedgerFilterType } from '@/lib/schema/ledger.schema';
import { useLedgerCampaignNames } from '../customer-drawer.campaign-names';
import {
  FILTER_TYPE_ADJUSTED,
  FILTER_TYPE_ALL,
  FILTER_TYPE_EARNED,
  FILTER_TYPE_EXPIRED,
  FILTER_TYPE_LABEL,
  FILTER_TYPE_REDEEMED,
  SEARCH_PLACEHOLDER,
} from '../customer-drawer.copy';
import { toLedgerRow } from '../customer-drawer.ledger-rows';
import { useDrawerTabQueryState } from '../customer-drawer.tab-query-state';
import { DrawerTabFilters } from './DrawerTabFilters';
import { DRAWER_PAGE_SIZE, DrawerTabPager } from './DrawerTabPager';
import { LedgerTabRows } from './LedgerTabRows';

const TYPE_LABELS: Record<LedgerFilterType, () => string> = {
  all: FILTER_TYPE_ALL,
  earn: FILTER_TYPE_EARNED,
  redeem: FILTER_TYPE_REDEEMED,
  adjust: FILTER_TYPE_ADJUSTED,
  expire: FILTER_TYPE_EXPIRED,
};

/** Filters, rows and pager. Each type segment shows its count, so the admin sees where to look before clicking. */
export function LedgerTab({
  userId,
  customerFirstName,
  causeLedgerId,
}: {
  userId: number;
  customerFirstName: string;
  /** The row that caused a negative balance, to mark, or null. */
  causeLedgerId: number | null;
}) {
  const state = useDrawerTabQueryState<LedgerFilterType>('all');
  const query = useCustomerLedgerQuery(userId, { ...state.pageParams, type: state.filter });

  const campaignNames = useLedgerCampaignNames();
  const rows = query.data?.data.map((entry) => toLedgerRow(entry, campaignNames, causeLedgerId)) ?? [];
  const totalItems = query.data?.totalItems ?? 0;
  // The counts describe the unfiltered set.
  const typeCounts = query.data?.type_counts;

  return (
    <div className="flex flex-col gap-3">
      <DrawerTabFilters
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
      />
      <div className="overflow-x-auto rounded-md border">
        <LedgerTabRows
          rows={rows}
          isLoading={query.isPending}
          isFiltered={state.isFiltered}
          customerFirstName={customerFirstName}
        />
      </div>
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
