import { useState, type ReactNode } from 'react';
import { Link } from 'react-router';
import { __ } from '@wordpress/i18n';

import { formatWPDate } from '@/lib/helpers/format.helper';
import { describeLedgerEntry, formatSignedPoints, ledgerFilterOf } from '@/lib/helpers/ledger.helper';
import { formatPoints } from '@/lib/helpers/points.helper';
import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import type { DashboardRecentActivity as Activity } from '@/lib/schema/dashboard.schema';
import type { LedgerFilterType } from '@/lib/schema/ledger.schema';
import { cn } from '@/lib/utils';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { UpgradeToProBadge } from '@/components/pro/UpgradeToPro';
import { activityDenominator } from '../dashboard-page.copy';
import { DashboardActivityToolbar } from './DashboardActivityToolbar';
import { DashboardInitialsAvatar } from './DashboardInitialsAvatar';
import { DashboardPanel, DashboardPanelLink } from './DashboardPanel';

const ROWS = 6;
const COLUMNS = 5;

function Frame({ note, toolbar, children }: { note?: string | null; toolbar: ReactNode; children: ReactNode }) {
  return (
    <DashboardPanel
      title={__('Recent activity', 'yayloyalty')}
      note={note}
      action={
        IS_PRO_BUILD ? (
          <DashboardPanelLink to="/ledger">{__('Open full ledger', 'yayloyalty')}</DashboardPanelLink>
        ) : (
          <span className="text-muted-foreground flex items-center gap-1.5 text-sm">
            {__('Open full ledger', 'yayloyalty')}
            <UpgradeToProBadge />
          </span>
        )
      }
    >
      <div className="flex flex-col gap-4 p-6">
        <div className="min-h-8">{toolbar}</div>
        <div className="overflow-hidden rounded-lg border">
          <Table>
            <TableHeader className="bg-muted/50">
              <TableRow>
                <TableHead>{__('Customer', 'yayloyalty')}</TableHead>
                <TableHead>{__('Event', 'yayloyalty')}</TableHead>
                <TableHead className="text-right">{__('Points', 'yayloyalty')}</TableHead>
                <TableHead className="text-right">{__('Balance after', 'yayloyalty')}</TableHead>
                <TableHead className="text-right">{__('Date', 'yayloyalty')}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>{children}</TableBody>
          </Table>
        </div>
      </div>
    </DashboardPanel>
  );
}

/** The dashboard names no tier: an entry bonus reads as plain bonus points. */
function describeRow(row: Activity['rows'][number]): string {
  return row.source === 'tier_bonus' ? __('Bonus points', 'yayloyalty') : describeLedgerEntry(row);
}

/**
 * Six newest ledger rows — a pulse check, not a feed: when more happened today than fits, the header says what
 * fraction is shown. The type tabs and search filter and count those six rows only. Event text comes from
 * `describeLedgerEntry`, never from the server.
 */
export function DashboardRecentActivity({ activity }: { activity: Activity }) {
  const [filter, setFilter] = useState<LedgerFilterType>('all');
  const [search, setSearch] = useState('');
  const needle = search.trim().toLowerCase();
  const rows = activity.rows
    .slice(0, ROWS)
    .filter((row) => !needle || [row.name, row.email, describeRow(row)].some((t) => t.toLowerCase().includes(needle)));
  const countOf = (f: LedgerFilterType) =>
    f === 'all' ? rows.length : rows.filter((row) => ledgerFilterOf(row.type) === f).length;
  const visible = filter === 'all' ? rows : rows.filter((row) => ledgerFilterOf(row.type) === filter);

  return (
    <Frame
      note={activityDenominator(Math.min(activity.rows.length, ROWS), activity.total_today)}
      toolbar={
        <DashboardActivityToolbar
          filter={filter}
          onFilterChange={setFilter}
          countOf={countOf}
          search={search}
          onSearchChange={setSearch}
        />
      }
    >
      {visible.length === 0 ? (
        <TableRow className="hover:bg-transparent">
          <TableCell colSpan={COLUMNS} className="text-muted-foreground py-6 text-center text-sm">
            {activity.rows.length === 0
              ? __('Nothing has happened yet. Points activity appears here as customers earn and redeem.', 'yayloyalty')
              : __('None of the latest activity matches.', 'yayloyalty')}
          </TableCell>
        </TableRow>
      ) : (
        visible.map((row) => <ActivityRow key={row.id} row={row} />)
      )}
    </Frame>
  );
}

function ActivityRow({ row }: { row: Activity['rows'][number] }) {
  const event = describeRow(row);

  return (
    <TableRow>
      <TableCell className="max-w-52">
        <Link
          to={`/customers/${row.user_id}`}
          className="text-foreground flex min-w-0 items-center gap-2.5 font-medium hover:underline"
        >
          <DashboardInitialsAvatar name={row.name} />
          <span className="flex min-w-0 flex-col">
            <span className="truncate">{row.name}</span>
            {row.email ? <span className="text-muted-foreground truncate text-xs font-normal">{row.email}</span> : null}
          </span>
        </Link>
      </TableCell>
      <TableCell className="max-w-56 truncate" title={event}>
        {event}
      </TableCell>
      <TableCell
        className={cn('text-right font-medium tabular-nums', row.points < 0 ? 'text-destructive' : 'text-success')}
      >
        {formatSignedPoints(row.points)}
      </TableCell>
      <TableCell className="text-muted-foreground text-right tabular-nums">{formatPoints(row.balance_after)}</TableCell>
      <TableCell className="text-muted-foreground text-right whitespace-nowrap">
        {formatWPDate(row.created_at, 'j M Y')}
      </TableCell>
    </TableRow>
  );
}

export function DashboardRecentActivitySkeleton() {
  return (
    <Frame toolbar={<Skeleton className="h-8 w-80" />}>
      {Array.from({ length: ROWS }).map((_, i) => (
        <TableRow key={i}>
          <TableCell>
            <span className="flex items-center gap-2.5">
              <Skeleton className="size-8 rounded-full" />
              <Skeleton className="h-3 w-28" />
            </span>
          </TableCell>
          <TableCell>
            <Skeleton className="h-3 w-48" />
          </TableCell>
          {[0, 1, 2].map((j) => (
            <TableCell key={j} className="text-right">
              <Skeleton className="ml-auto h-3 w-12" />
            </TableCell>
          ))}
        </TableRow>
      ))}
    </Frame>
  );
}
