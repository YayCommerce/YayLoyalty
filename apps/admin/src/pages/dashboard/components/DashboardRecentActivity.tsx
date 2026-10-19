import { Link } from 'react-router';
import { __ } from '@wordpress/i18n';

import { parseWPRelativeDate } from '@/lib/helpers/format.helper';
import { describeLedgerEntry, formatSignedPoints } from '@/lib/helpers/ledger.helper';
import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import type { DashboardRecentActivity as Activity } from '@/lib/schema/dashboard.schema';
import { cn } from '@/lib/utils';
import { Card, CardAction, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { UpgradeToProBadge } from '@/components/pro/UpgradeToPro';
import { activityDenominator } from '../dashboard-page.copy';

const ROWS = 6;

function Frame({ description, children }: { description?: string | null; children: React.ReactNode }) {
  return (
    <Card className="gap-3">
      <CardHeader>
        <CardTitle>{__('Recent activity', 'yayloyalty')}</CardTitle>
        {description ? <CardDescription>{description}</CardDescription> : null}
        <CardAction>
          {IS_PRO_BUILD ? (
            <Link to="/ledger" className="text-primary text-sm hover:underline">
              {__('Open full ledger', 'yayloyalty')}
            </Link>
          ) : (
            <span className="text-muted-foreground flex items-center gap-1.5 text-sm">
              {__('Open full ledger', 'yayloyalty')}
              <UpgradeToProBadge />
            </span>
          )}
        </CardAction>
      </CardHeader>
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>{__('Customer', 'yayloyalty')}</TableHead>
            <TableHead>{__('Event', 'yayloyalty')}</TableHead>
            <TableHead className="text-right">{__('Points', 'yayloyalty')}</TableHead>
            <TableHead className="text-right">{__('When', 'yayloyalty')}</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>{children}</TableBody>
      </Table>
    </Card>
  );
}

/**
 * Six newest ledger rows — a pulse check, not a feed: when
 * more happened today than fits, the header says what fraction is shown.
 * Event text comes from `describeLedgerEntry`, never from the server.
 */
export function DashboardRecentActivity({ activity }: { activity: Activity }) {
  const rows = activity.rows.slice(0, ROWS);

  return (
    <Frame description={activityDenominator(rows.length, activity.total_today)}>
      {rows.length === 0 ? (
        <TableRow>
          <TableCell colSpan={4} className="text-muted-foreground py-6 text-center text-sm">
            {__('Nothing has happened yet. Points activity appears here as customers earn and redeem.', 'yayloyalty')}
          </TableCell>
        </TableRow>
      ) : (
        rows.map((row) => {
          const event = describeLedgerEntry(row);

          return (
            <TableRow key={row.id}>
              <TableCell className="max-w-40 truncate">
                <Link to={`/customers/${row.user_id}`} className="hover:underline">
                  {row.name}
                </Link>
              </TableCell>
              <TableCell className="text-muted-foreground max-w-64 truncate text-xs" title={event}>
                {event}
              </TableCell>
              <TableCell className={cn('text-right font-medium tabular-nums', row.points < 0 && 'text-destructive')}>
                {formatSignedPoints(row.points)}
              </TableCell>
              <TableCell className="text-muted-foreground text-right text-xs whitespace-nowrap">
                {parseWPRelativeDate(row.created_at)}
              </TableCell>
            </TableRow>
          );
        })
      )}
    </Frame>
  );
}

export function DashboardRecentActivitySkeleton() {
  return (
    <Frame>
      {Array.from({ length: ROWS }).map((_, i) => (
        <TableRow key={i}>
          <TableCell>
            <Skeleton className="h-3 w-32" />
          </TableCell>
          <TableCell>
            <Skeleton className="h-3 w-48" />
          </TableCell>
          <TableCell className="text-right">
            <Skeleton className="ml-auto h-3 w-12" />
          </TableCell>
          <TableCell className="text-right">
            <Skeleton className="ml-auto h-3 w-10" />
          </TableCell>
        </TableRow>
      ))}
    </Frame>
  );
}
