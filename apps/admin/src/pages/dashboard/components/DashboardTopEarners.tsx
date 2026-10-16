import { __ } from '@wordpress/i18n';

import { Link } from '@/lib/router';
import type { DashboardTopEarner } from '@/lib/schema/dashboard.schema';
import { Card, CardAction, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { PointsValue } from '@/components/ui/custom/points-value';
import { Skeleton } from '@/components/ui/skeleton';

const ROWS = 3;

function Frame({ children }: { children: React.ReactNode }) {
  return (
    <Card className="gap-3">
      <CardHeader>
        <CardTitle>{__('Top earners', 'yayloyalty')}</CardTitle>
        <CardDescription>{__('by current balance', 'yayloyalty')}</CardDescription>
        <CardAction>
          <Link to="/customers" className="text-primary text-sm hover:underline">
            {__('View all customers', 'yayloyalty')}
          </Link>
        </CardAction>
      </CardHeader>
      <ul className="flex flex-col divide-y">{children}</ul>
    </Card>
  );
}

/** Three highest balances. Always three row-heights tall so the side column never jumps. */
export function DashboardTopEarners({ earners }: { earners: DashboardTopEarner[] }) {
  return (
    <Frame>
      {earners.length === 0 ? (
        <li className="text-muted-foreground py-2 text-sm">{__('No members yet.', 'yayloyalty')}</li>
      ) : (
        earners.slice(0, ROWS).map((row) => (
          <li key={row.user_id} className="flex items-center justify-between gap-3 py-2 text-sm">
            <span className="truncate">{row.name}</span>
            <PointsValue value={row.balance} className="font-medium tabular-nums" />
          </li>
        ))
      )}
    </Frame>
  );
}

export function DashboardTopEarnersSkeleton() {
  return (
    <Frame>
      {Array.from({ length: ROWS }).map((_, i) => (
        <li key={i} className="flex items-center justify-between gap-3 py-2.5">
          <Skeleton className="h-3 w-32" />
          <Skeleton className="h-3 w-12" />
        </li>
      ))}
    </Frame>
  );
}
