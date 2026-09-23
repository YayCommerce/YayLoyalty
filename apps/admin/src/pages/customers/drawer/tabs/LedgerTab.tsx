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
  FILTER_UNAVAILABLE_REASON,
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

/**
 * Filters → rows → pager over `useDrawerTabQueryState`. The type filter is
 * a segmented control with every per-type count on its segment — "4,190
 * earned, 38 adjusted" tells the admin where to look before they click
 * (3c note 3). `type_counts` is `null` today (no type-count
 * query yet), which disables the whole row with a stated reason.
 */
export function LedgerTab({
  userId,
  customerFirstName,
  causeLedgerId,
}: {
  userId: number;
  customerFirstName: string;
  /** 5a's `cause_ledger_id` — the one row to mark, or `null` whenever there is no negative-balance story. */
  causeLedgerId: number | null;
}) {
  const state = useDrawerTabQueryState<LedgerFilterType>('all');
  const query = useCustomerLedgerQuery(userId, { ...state.pageParams, type: state.filter });

  const campaignNames = useLedgerCampaignNames();
  const rows = query.data?.data.map((entry) => toLedgerRow(entry, campaignNames, causeLedgerId)) ?? [];
  const totalItems = query.data?.totalItems ?? 0;
  // Per-type counts describe the UNFILTERED set (see the schema's own docblock).
  const typeCounts = query.data?.type_counts ?? null;

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
        disabled={typeCounts === null}
        disabledReason={FILTER_UNAVAILABLE_REASON()}
      />
      <div className="overflow-x-auto rounded-md border">
        <LedgerTabRows rows={rows} isLoading={query.isPending} customerFirstName={customerFirstName} />
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
