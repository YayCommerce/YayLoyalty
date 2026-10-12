import { Link } from 'react-router';

import type { StoreLedgerEntry } from '@/lib/schema/store-ledger.schema';
import { cn } from '@/lib/utils';
import { YayLoyaltyToolTip } from '@/components/ui/custom/tooltip';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { COLUMN_DATE, COLUMN_POINTS } from '@/pages/customers/drawer/customer-drawer.copy';
import { toLedgerRow } from '@/pages/customers/drawer/customer-drawer.ledger-rows';
import {
  BALANCE_AFTER_HINT,
  COLUMN_BALANCE_AFTER,
  COLUMN_CUSTOMER,
  COLUMN_EVENT,
  EMPTY_FILTERED,
  EMPTY_STORE,
} from './store-ledger.copy';

const COLUMN_COUNT = 5;

/** Headers stay through every state, so the columns never shift when rows land. */
export function StoreLedgerTable({
  entries,
  campaignNames,
  isLoading,
  isFiltered,
  skeletonRows,
}: {
  entries: StoreLedgerEntry[];
  campaignNames: Map<number, string>;
  isLoading: boolean;
  isFiltered: boolean;
  skeletonRows: number;
}) {
  return (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>{COLUMN_DATE()}</TableHead>
          <TableHead>{COLUMN_CUSTOMER()}</TableHead>
          <TableHead>{COLUMN_EVENT()}</TableHead>
          <TableHead className="text-right">{COLUMN_POINTS()}</TableHead>
          <TableHead className="text-right">
            <span className="inline-flex items-center gap-1">
              {COLUMN_BALANCE_AFTER()}
              <YayLoyaltyToolTip content={BALANCE_AFTER_HINT()} />
            </span>
          </TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {isLoading ? (
          Array.from({ length: skeletonRows }).map((_, index) => (
            <TableRow key={index}>
              {Array.from({ length: COLUMN_COUNT }).map((_cell, cell) => (
                <TableCell key={cell}>
                  <Skeleton className={cn('h-3 w-20', cell >= 3 && 'ml-auto w-12')} />
                </TableCell>
              ))}
            </TableRow>
          ))
        ) : entries.length === 0 ? (
          <TableRow>
            <TableCell colSpan={COLUMN_COUNT} className="text-muted-foreground py-10 text-center text-sm">
              {isFiltered ? EMPTY_FILTERED() : EMPTY_STORE()}
            </TableCell>
          </TableRow>
        ) : (
          entries.map((entry) => {
            const row = toLedgerRow(entry, campaignNames);

            return (
              <TableRow key={entry.id}>
                <TableCell className="text-muted-foreground whitespace-nowrap">{row.date}</TableCell>
                <TableCell className="max-w-56">
                  <span className="flex flex-col gap-0.5">
                    <Link to={`/ledger/${entry.user_id}`} className="truncate font-medium hover:underline">
                      {entry.customer_name}
                    </Link>
                    {entry.customer_email && (
                      <span className="text-muted-foreground truncate text-xs">{entry.customer_email}</span>
                    )}
                  </span>
                </TableCell>
                <TableCell>
                  <span className="flex flex-col gap-0.5">
                    <span>{row.description}</span>
                    {row.subLine && <span className="text-muted-foreground text-xs">{row.subLine}</span>}
                  </span>
                </TableCell>
                <TableCell
                  className={cn(
                    'text-right tabular-nums',
                    row.points.startsWith('-') && 'text-destructive',
                    row.points.startsWith('+') && 'text-success',
                  )}
                >
                  {row.points}
                </TableCell>
                <TableCell className="text-muted-foreground text-right tabular-nums">{row.balance}</TableCell>
              </TableRow>
            );
          })
        )}
      </TableBody>
    </Table>
  );
}
