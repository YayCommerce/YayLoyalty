import { useState } from 'react';

import { useCustomerRedemptionsQuery } from '@/lib/queries/customer.queries';
import { toRedemptionRow } from '../customer-drawer.redemption-rows';
import { DrawerTabPager } from './DrawerTabPager';
import { RedemptionsTabRows } from './RedemptionsTabRows';

/** Matches `LedgerTab.tsx`'s own `PER_PAGE` — every paged drawer tab pages alike. */
const PER_PAGE = 10;

/**
 * 3e — table only (the summary strip and "Rewards X could use now" are cut,
 * see the phase brief). Owns paging; composes rows → pager. Simpler than
 * `LedgerTab`/`ActivityLogTab`: no filters this phase, so no filter state to
 * own or reset.
 */
export function RedemptionsTab({
  userId,
  customerFirstName,
  scenario,
}: {
  userId: number;
  customerFirstName: string;
  scenario?: string;
}) {
  const [page, setPage] = useState(1);

  const query = useCustomerRedemptionsQuery(userId, { page, perPage: PER_PAGE, scenario });

  const rows = query.data?.data.map(toRedemptionRow) ?? [];
  const totalItems = query.data?.totalItems ?? 0;

  return (
    <div className="flex flex-col gap-3">
      <div className="overflow-x-auto rounded-md border">
        <RedemptionsTabRows rows={rows} isLoading={query.isPending} customerFirstName={customerFirstName} />
      </div>
      <DrawerTabPager
        page={page}
        perPage={PER_PAGE}
        totalItems={totalItems}
        onPageChange={setPage}
        disabled={query.isFetching}
        isPending={query.isPending}
      />
    </div>
  );
}
