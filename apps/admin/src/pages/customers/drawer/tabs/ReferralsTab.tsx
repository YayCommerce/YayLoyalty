import { useState } from 'react';

import { useCustomerReferralsQuery } from '@/lib/queries/customer.queries';
import { toReferralRow } from '../customer-drawer.referral-rows';
import { DrawerTabPager } from './DrawerTabPager';
import { ReferralsTabHeader } from './ReferralsTabHeader';
import { ReferralsTabRows } from './ReferralsTabRows';

/** Matches every other paged drawer tab's own `PER_PAGE`. */
const PER_PAGE = 10;

/**
 * 3f — table only (the summary strip and "Revenue referred" are cut, see
 * the phase brief). Structurally a clone of `RedemptionsTab.tsx`: owns
 * paging, no filters this phase, plus the link/campaign header 3e doesn't have.
 */
export function ReferralsTab({ userId, scenario }: { userId: number; scenario?: string }) {
  const [page, setPage] = useState(1);

  const query = useCustomerReferralsQuery(userId, { page, perPage: PER_PAGE, scenario });

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
        perPage={PER_PAGE}
        totalItems={totalItems}
        onPageChange={setPage}
        disabled={query.isFetching}
        isPending={query.isPending}
      />
    </div>
  );
}
