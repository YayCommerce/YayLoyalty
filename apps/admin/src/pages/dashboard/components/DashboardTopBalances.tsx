import { Link } from 'react-router';
import { __ } from '@wordpress/i18n';

import type { DashboardTopBalance } from '@/lib/schema/dashboard.schema';
import { PointsValue } from '@/components/ui/custom/points-value';
import { Skeleton } from '@/components/ui/skeleton';
import TierCrownIcon from '@/components/icons/TierCrownIcon';
import { DashboardInitialsAvatar } from './DashboardInitialsAvatar';
import { DashboardPanel, DashboardPanelLink } from './DashboardPanel';

const ROWS = 5;

function Frame({ children }: { children: React.ReactNode }) {
  return (
    <DashboardPanel
      title={__('Top earners', 'yayloyalty')}
      action={<DashboardPanelLink to="/customers">{__('View customers', 'yayloyalty')}</DashboardPanelLink>}
    >
      <ul className="m-0 flex flex-col gap-1 px-6 py-3">{children}</ul>
    </DashboardPanel>
  );
}

/** Five highest balances. Always five row-heights tall so the side column never jumps. */
export function DashboardTopBalances({ balances }: { balances: DashboardTopBalance[] }) {
  return (
    <Frame>
      {balances.length === 0 ? (
        <li className="text-muted-foreground m-0 py-3 text-sm">{__('No members yet.', 'yayloyalty')}</li>
      ) : (
        balances.slice(0, ROWS).map((row, i) => (
          <li key={row.user_id} className="m-0 flex items-center justify-between gap-3 py-2.5 text-sm">
            <Link
              to={`/customers/${row.user_id}`}
              className="text-foreground flex min-w-0 items-center gap-3 font-medium hover:underline"
            >
              <DashboardInitialsAvatar name={row.name} rank={i + 1} badgeIcon={<TierCrownIcon />} className="size-9" />
              <span className="truncate">{row.name}</span>
            </Link>
            <PointsValue value={row.balance} className="font-semibold tabular-nums" />
          </li>
        ))
      )}
    </Frame>
  );
}

export function DashboardTopBalancesSkeleton() {
  return (
    <Frame>
      {Array.from({ length: ROWS }).map((_, i) => (
        <li key={i} className="m-0 flex items-center justify-between gap-3 py-2.5">
          <span className="flex items-center gap-3">
            <Skeleton className="size-9 rounded-full" />
            <Skeleton className="h-3 w-32" />
          </span>
          <Skeleton className="h-3 w-12" />
        </li>
      ))}
    </Frame>
  );
}
