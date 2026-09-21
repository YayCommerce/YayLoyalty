import { cn } from '@/lib/utils';
import { TableCell, TableHead, TableRow } from '@/components/ui/table';
import {
  COLUMN_BALANCE,
  COLUMN_DATE,
  COLUMN_DESCRIPTION,
  COLUMN_POINTS,
  EMPTY_LEDGER_TITLE,
  emptyLedgerBody,
} from '../customer-drawer.copy';
import type { LedgerRow } from '../customer-drawer.ledger-rows';
import { DRAWER_ROW_CLASS, drawerCell, DrawerTable } from './DrawerTable';

/**
 * Headers stay put through every state (3a note 3 — an empty ledger keeps
 * its column headers and filter row so the panel doesn't reflow the moment
 * the first row lands). Below `sm`, each row is two stacked blocks side by
 * side: description+subLine+date on the left, points+balance on the right —
 * date reads as part of the description block rather than its own cell.
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
    <DrawerTable
      columnCount={4}
      isLoading={isLoading}
      isEmpty={rows.length === 0}
      emptyTitle={EMPTY_LEDGER_TITLE()}
      emptyBody={emptyLedgerBody(customerFirstName)}
      head={
        <TableRow>
          <TableHead>{COLUMN_DATE()}</TableHead>
          <TableHead>{COLUMN_DESCRIPTION()}</TableHead>
          <TableHead className="text-right">{COLUMN_POINTS()}</TableHead>
          <TableHead className="text-right">{COLUMN_BALANCE()}</TableHead>
        </TableRow>
      }
    >
      {/* Date and Balance keep their own `<td>` for the desktop columns
          (`hidden sm:table-cell`) and are rendered once more, inline, inside
          the left/right blocks (`sm:hidden`) — "mounts twice, CSS picks one",
          so there is one source of truth for the value and no JS branching
          on breakpoint. */}
      {rows.map((row, index) => (
        <TableRow
          key={index}
          // 5a: the one row `negative_balance.cause_ledger_id` names gets the
          // same tinted-panel treatment the header panels use. Applied via
          // `box-shadow` (not `border-l`) because Tailwind's border utilities
          // on a `<tr>` render unreliably across browsers.
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
