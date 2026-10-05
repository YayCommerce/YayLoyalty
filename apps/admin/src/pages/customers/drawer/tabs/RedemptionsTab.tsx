import { useState } from 'react';

import { useCustomerRedemptionsQuery } from '@/lib/queries/customer.queries';
import { toRedemptionRow } from '../customer-drawer.redemption-rows';
import { DRAWER_PAGE_SIZE, DrawerTabPager } from './DrawerTabPager';
import { RedemptionsTabRows } from './RedemptionsTabRows';

/** The redemptions table and pager. No filters, so it owns only the page. */
export function RedemptionsTab({ userId, customerFirstName }: { userId: number; customerFirstName: string }) {
  const [page, setPage] = useState(1);

  const query = useCustomerRedemptionsQuery(userId, { page, perPage: DRAWER_PAGE_SIZE });

  const rows = query.data?.data.map(toRedemptionRow) ?? [];
  const totalItems = query.data?.totalItems ?? 0;

  return (
    <div className="flex flex-col gap-3">
      <div className="overflow-x-auto rounded-md border">
        <RedemptionsTabRows rows={rows} isLoading={query.isPending} customerFirstName={customerFirstName} />
      </div>
      <DrawerTabPager
        page={page}
        perPage={DRAWER_PAGE_SIZE}
        totalItems={totalItems}
        onPageChange={setPage}
        disabled={query.isFetching}
        isPending={query.isPending}
      />
    </div>
  );
}
