import { useState } from 'react';

import { useCustomerReferralsQuery } from '@/lib/queries/customer.queries';
import { toReferralRow } from '../customer-drawer.referral-rows';
import { DRAWER_PAGE_SIZE, DrawerTabPager } from './DrawerTabPager';
import { ReferralsTabHeader } from './ReferralsTabHeader';
import { ReferralsTabRows } from './ReferralsTabRows';

/** The referral link header, table and pager. No filters, so it owns only the page. */
export function ReferralsTab({ userId }: { userId: number }) {
  const [page, setPage] = useState(1);

  const query = useCustomerReferralsQuery(userId, { page, perPage: DRAWER_PAGE_SIZE });

  const rows = query.data?.data.map(toReferralRow) ?? [];
  const totalItems = query.data?.totalItems ?? 0;

  return (
    <div className="flex flex-col gap-3">
      <ReferralsTabHeader referralLink={query.data?.referral_link ?? ''} campaign={query.data?.campaign ?? null} />
      <div className="overflow-x-auto rounded-md border">
        <ReferralsTabRows rows={rows} isLoading={query.isPending} />
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
