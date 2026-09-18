import { cn } from '@/lib/utils';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import type { LedgerRow } from '../customer-drawer.ledger-rows';
import {
  COLUMN_BALANCE,
  COLUMN_DATE,
  COLUMN_DESCRIPTION,
  COLUMN_POINTS,
  EMPTY_LEDGER_TITLE,
  emptyLedgerBody,
} from '../customer-drawer.copy';

const COLUMN_COUNT = 4;
/** Matches the real ledger page size — see `LedgerTab.tsx`'s `PER_PAGE`. */
const SKELETON_ROW_COUNT = 10;

/**
 * Headers stay put through every state (3a note 3 — empty ledger keeps its
 * column headers and filter row so the panel doesn't reflow the moment the
 * first row lands). Below `sm`, each row stacks description+points on one
 * line and date+balance on the next instead of four columns (5c note 5).
 */
export function LedgerTabRows({
  rows,
  isLoading,
  customerFirstName,
}: {
  rows: LedgerRow[];
  isLoading: boolean;
  customerFirstName: string;
}) {
  return (
    // `min-w-max` overrides `Table`'s own `w-full` — without it the table can
    // never be wider than its `overflow-x-auto` ancestor, so `whitespace-nowrap`
    // cells get squeezed/clipped instead of the container ever scrolling.
    // Same flexbox-style sizing trap as CustomerDrawer.tsx's vertical scroll
    // fix, on the width axis instead of height.
    <Table className="min-w-max">
      <TableHeader>
        <TableRow>
          <TableHead>{COLUMN_DATE()}</TableHead>
          <TableHead>{COLUMN_DESCRIPTION()}</TableHead>
          <TableHead className="text-right">{COLUMN_POINTS()}</TableHead>
          <TableHead className="text-right">{COLUMN_BALANCE()}</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {isLoading ? (
          Array.from({ length: SKELETON_ROW_COUNT }, (_, index) => (
            <TableRow key={index}>
              <TableCell colSpan={COLUMN_COUNT}>
                <Skeleton className="h-5 w-full" />
              </TableCell>
            </TableRow>
          ))
        ) : rows.length === 0 ? (
          <TableRow>
            <TableCell colSpan={COLUMN_COUNT} className="h-auto py-12 text-center">
              <p className="text-sm font-medium">{EMPTY_LEDGER_TITLE()}</p>
              <p className="text-muted-foreground mx-auto mt-1 max-w-sm text-sm">
                {emptyLedgerBody(customerFirstName)}
              </p>
            </TableCell>
          </TableRow>
        ) : (
          // Below `sm`: `flex-wrap` + each cell at `w-1/2` packs two cells per
          // visual line; `order-*` puts Description+Points on line 1 and
          // Date+Balance on line 2 (5c note 5) without reparenting anything —
          // `sm:table-row`/`sm:table-cell`/`sm:w-auto` restore the real table
          // layout above that breakpoint, so column headers stay aligned.
          rows.map((row, index) => (
            <TableRow key={index} className="flex flex-wrap sm:table-row">
              <TableCell className="order-3 w-1/2 sm:order-0 sm:w-auto sm:table-cell">{row.date}</TableCell>
              <TableCell className="order-1 w-1/2 sm:order-0 sm:w-auto sm:table-cell">
                <span className="flex flex-col gap-0.5">
                  <span>{row.description}</span>
                  {row.subLine && <span className="text-muted-foreground text-xs">{row.subLine}</span>}
                </span>
              </TableCell>
              <TableCell
                className={cn(
                  'order-2 w-1/2 text-right tabular-nums sm:order-0 sm:w-auto sm:table-cell',
                  row.points.startsWith('-') && 'text-destructive',
                  row.points.startsWith('+') && 'text-success',
                )}
              >
                {row.points}
              </TableCell>
              <TableCell className="text-muted-foreground order-4 w-1/2 text-right tabular-nums sm:order-0 sm:w-auto sm:table-cell">
                {row.balance}
              </TableCell>
            </TableRow>
          ))
        )}
      </TableBody>
    </Table>
  );
}
