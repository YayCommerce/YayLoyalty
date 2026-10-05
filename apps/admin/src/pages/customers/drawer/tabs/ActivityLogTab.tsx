import { useCustomerActivityQuery } from '@/lib/queries/customer.queries';
import { ACTIVITY_GROUPS, type ActivityGroup } from '@/lib/schema/activity.schema';
import { toActivityRow } from '../customer-drawer.activity-rows';
import {
  ACTIVITY_FOOTER_NOTE,
  ACTIVITY_GROUP_ADMIN,
  ACTIVITY_GROUP_ALL,
  ACTIVITY_GROUP_EMAILS,
  ACTIVITY_GROUP_EXPIRY,
  ACTIVITY_GROUP_LABEL,
  ACTIVITY_GROUP_TIER,
  ACTIVITY_SEARCH_PLACEHOLDER,
} from '../customer-drawer.activity.copy';
import { useDrawerTabQueryState } from '../customer-drawer.tab-query-state';
import { ActivityLogTabRows } from './ActivityLogTabRows';
import { DrawerTabFilters } from './DrawerTabFilters';
import { DRAWER_PAGE_SIZE, DrawerTabPager } from './DrawerTabPager';

const GROUP_LABELS: Record<ActivityGroup, () => string> = {
  all: ACTIVITY_GROUP_ALL,
  admin: ACTIVITY_GROUP_ADMIN,
  tier: ACTIVITY_GROUP_TIER,
  expiry: ACTIVITY_GROUP_EXPIRY,
  emails: ACTIVITY_GROUP_EMAILS,
};

/** Filters, rows and pager, like LedgerTab. Paged with the full filter set: a large activity log is unbrowsable otherwise. */
export function ActivityLogTab({ userId }: { userId: number }) {
  const state = useDrawerTabQueryState<ActivityGroup>('all');
  const query = useCustomerActivityQuery(userId, { ...state.pageParams, group: state.filter });

  const rows = query.data?.data.map(toActivityRow) ?? [];
  const totalItems = query.data?.totalItems ?? 0;

  return (
    <div className="flex flex-col gap-3">
      <DrawerTabFilters
        options={ACTIVITY_GROUPS.map((group) => ({ value: group, label: GROUP_LABELS[group]() }))}
        value={state.filter}
        onChange={state.setFilter}
        ariaLabel={ACTIVITY_GROUP_LABEL()}
        range={state.range}
        onRangeChange={state.setRange}
        search={state.search}
        onSearchChange={state.setSearch}
        searchPlaceholder={ACTIVITY_SEARCH_PLACEHOLDER()}
      />
      <div className="overflow-x-auto rounded-md border">
        <ActivityLogTabRows rows={rows} isLoading={query.isPending} isFiltered={state.isFiltered} />
      </div>
      <DrawerTabPager
        page={state.page}
        perPage={DRAWER_PAGE_SIZE}
        totalItems={totalItems}
        onPageChange={state.setPage}
        disabled={query.isFetching}
        isPending={query.isPending}
      />
      <p className="text-muted-foreground border-t pt-3 text-xs">{ACTIVITY_FOOTER_NOTE()}</p>
    </div>
  );
}
