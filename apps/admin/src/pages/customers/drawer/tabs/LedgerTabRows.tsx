import { cn } from '@/lib/utils';
import { TableCell, TableHead, TableRow } from '@/components/ui/table';
import {
  COLUMN_BALANCE,
  COLUMN_DATE,
  COLUMN_DESCRIPTION,
  COLUMN_POINTS,
  EMPTY_FILTERED_BODY,
  EMPTY_FILTERED_TITLE,
  EMPTY_LEDGER_TITLE,
  emptyLedgerBody,
} from '../customer-drawer.copy';
import type { LedgerRow } from '../customer-drawer.ledger-rows';
import { DRAWER_ROW_CLASS, drawerCell, DrawerTable } from './DrawerTable';

/**
 * Headers stay through every state, so an empty ledger does not reflow when its first row lands.
 * Below `sm` each row is two blocks: description and date on the left, points and balance on the right.
 */
export function LedgerTabRows({
  rows,
  isLoading,
  isFiltered,
  customerFirstName,
}: {
  rows: LedgerRow[];
  isLoading: boolean;
  isFiltered: boolean;
  customerFirstName: string;
}) {
  return (
    <DrawerTable
      columnCount={4}
      isLoading={isLoading}
      isEmpty={rows.length === 0}
      emptyTitle={isFiltered ? EMPTY_FILTERED_TITLE() : EMPTY_LEDGER_TITLE()}
      emptyBody={isFiltered ? EMPTY_FILTERED_BODY() : emptyLedgerBody(customerFirstName)}
      head={
        <TableRow>
          <TableHead>{COLUMN_DATE()}</TableHead>
          <TableHead>{COLUMN_DESCRIPTION()}</TableHead>
          <TableHead className="text-right">{COLUMN_POINTS()}</TableHead>
          <TableHead className="text-right">{COLUMN_BALANCE()}</TableHead>
        </TableRow>
      }
    >
      {/* Date and Balance render twice, as desktop cells and inside the mobile blocks. CSS picks one. */}
      {rows.map((row, index) => (
        <TableRow
          key={index}
          // The cause row gets the panels' tint, through `box-shadow`: a border on a `<tr>` renders unreliably.
          className={cn(
            DRAWER_ROW_CLASS,
            row.isCause && 'bg-muted/50 shadow-[inset_3px_0_0_0_var(--muted-foreground)]',
          )}
        >
          <TableCell className="hidden sm:table-cell">{row.date}</TableCell>
          <TableCell className={drawerCell(1, 'h-auto sm:h-13')}>
            <span className="flex flex-col gap-0.5">
              <span>{row.description}</span>
              {row.subLine && <span className="text-muted-foreground text-xs">{row.subLine}</span>}
              <span className="text-muted-foreground text-xs sm:hidden">{row.date}</span>
            </span>
          </TableCell>
          <TableCell
            className={drawerCell(
              2,
              cn(
                'h-auto text-right tabular-nums sm:h-13',
                row.points.startsWith('-') && 'text-destructive',
                row.points.startsWith('+') && 'text-success',
              ),
            )}
          >
            <span className="flex flex-col items-end gap-0.5">
              <span>{row.points}</span>
              <span className="text-muted-foreground sm:hidden">{row.balance}</span>
            </span>
          </TableCell>
          <TableCell className="text-muted-foreground hidden text-right tabular-nums sm:table-cell">
            {row.balance}
          </TableCell>
        </TableRow>
      ))}
    </DrawerTable>
  );
}
