import { GiftIcon } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { EMPTY_REDEMPTIONS_TITLE, emptyRedemptionsBody, redemptionDetails } from '../customer-drawer.copy';
import type { RedemptionRow } from '../customer-drawer.redemption-rows';
import { DrawerPointsBadge } from './DrawerPointsBadge';
import { DrawerTimeline } from './DrawerTimeline';

/** Redemptions as a timeline: the reward, its value and order underneath, the points and status on the right. */
export function RedemptionsTabRows({
  rows,
  isLoading,
  customerFirstName,
}: {
  rows: RedemptionRow[];
  isLoading: boolean;
  customerFirstName: string;
}) {
  return (
    <DrawerTimeline
      isLoading={isLoading}
      emptyTitle={EMPTY_REDEMPTIONS_TITLE()}
      emptyBody={emptyRedemptionsBody(customerFirstName)}
      items={rows.map((row, index) => ({
        key: index,
        date: row.date,
        icon: GiftIcon,
        title: row.reward,
        subLines: [redemptionDetails(row.value, row.order)],
        trailing: (
          <>
            <DrawerPointsBadge points={Number(row.points)} />
            <Badge variant="outline">{row.status}</Badge>
          </>
        ),
      }))}
    />
  );
}
