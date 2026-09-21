import { cn } from '@/lib/utils';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import {
  COLUMN_BALANCE,
  COLUMN_DATE,
  COLUMN_DESCRIPTION,
  COLUMN_POINTS,
  EMPTY_LEDGER_TITLE,
  emptyLedgerBody,
} from '../customer-drawer.copy';
import type { LedgerRow } from '../customer-drawer.ledger-rows';

const COLUMN_COUNT = 4;
/** Matches the real ledger page size — see `LedgerTab.tsx`'s `PER_PAGE`. */
const SKELETON_ROW_COUNT = 10;

/**
 * Headers stay put through every state (3a note 3 — empty ledger keeps its
 * column headers and filter row so the panel doesn't reflow the moment the
 * first row lands, though the header itself is hidden below `sm` — see the
 * row markup below). Below `sm`, each row is two stacked blocks side by
 * side: description+subLine+date on the left, points+balance on the right —
 * not four separate columns (5c note 5's stacking, refined after review so
 * date reads as part of the description block rather than its own cell).
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
    // `min-w-max` overrides `Table`'s own `w-full` at `sm` and up — without it
    // the desktop table can never be wider than its `overflow-x-auto`
    // ancestor, so `whitespace-nowrap` cells get squeezed/clipped instead of
    // the container ever scrolling. `sm:`-scoped, not unconditional: applied
    // below `sm` too, it forces the table to its one-line desktop width even
    // though rows are `flex flex-wrap` there, which breaks the intended
    // 2-per-line stacking (cells report their un-wrapped nowrap width as the
    // table's intrinsic size, so `w-1/2` resolves against a much wider
    // reference than the actual mobile viewport — description text overflows
    // its cell instead of wrapping within it).
    //
    // `block`/`sm:table` on `Table`/`TableBody` (and hiding `TableHeader`)
    // below `sm`: a `<tr>` given `display:flex` while its `<table>`/`<tbody>`
    // ancestors stay real table display does NOT stretch to the table's
    // width in Chromium — verified live (agent-browser): the row shrank to
    // ~168px inside a 397px table, so `w-1/2` cells came out ~84px wide,
    // wrapped their text into far more lines than the fixed `h-13` allows,
    // and the overflow visually covered the next line. Taking `table`/`tbody`
    // out of table layout too (not just `tr`/`td`) fixes the row's width;
    // confirmed by patching the live DOM before writing this. No header row
    // on mobile — a stacked two-line card doesn't need repeated column labels.
    <Table className="block sm:table sm:min-w-max">
      <TableHeader className="hidden sm:table-header-group">
        <TableRow>
          <TableHead>{COLUMN_DATE()}</TableHead>
          <TableHead>{COLUMN_DESCRIPTION()}</TableHead>
          <TableHead className="text-right">{COLUMN_POINTS()}</TableHead>
          <TableHead className="text-right">{COLUMN_BALANCE()}</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody className="block sm:table-row-group">
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
          // Below `sm`: two flex-col blocks side by side, not a 2×2 grid of
          // four separate cells — description+subLine+date stack together on
          // the left, points+balance stack together on the right, both at
          // `w-1/2` so they sit on one line. Date and Balance keep their own
          // `<td>` for the real desktop columns (`hidden sm:table-cell`) and
          // are duplicated once more, inline, inside the left/right blocks
          // (`sm:hidden`) — "mounts twice, CSS picks one", so there is one
          // source of truth for the value and no JS branching on breakpoint.
          rows.map((row, index) => (
            <TableRow
              key={index}
              // 5a (F09 Wave B): the one row `negative_balance.cause_ledger_id`
              // names gets the same tinted-panel treatment the tier card and
              // other header panels use — never a new colour token. Applied
              // via `box-shadow` (not `border-l`) because Tailwind's border
              // utilities on a `<tr>` render unreliably across browsers; an
              // inset shadow paints correctly regardless of table layout.
              className={cn(
                'flex flex-wrap sm:table-row',
                row.isCause && 'bg-muted/50 shadow-[inset_3px_0_0_0_var(--muted-foreground)]',
              )}
            >
              <TableCell className="hidden sm:table-cell">{row.date}</TableCell>
              <TableCell className="order-1 h-auto w-1/2 whitespace-normal sm:order-0 sm:h-13 sm:table-cell sm:w-auto sm:whitespace-nowrap">
                <span className="flex flex-col gap-0.5">
                  <span>{row.description}</span>
                  {row.subLine && <span className="text-muted-foreground text-xs">{row.subLine}</span>}
                  <span className="text-muted-foreground text-xs sm:hidden">{row.date}</span>
                </span>
              </TableCell>
              <TableCell
                className={cn(
                  'order-2 h-auto w-1/2 text-right whitespace-normal tabular-nums sm:order-0 sm:h-13 sm:table-cell sm:w-auto sm:whitespace-nowrap',
                  row.points.startsWith('-') && 'text-destructive',
                  row.points.startsWith('+') && 'text-success',
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
          ))
        )}
      </TableBody>
    </Table>
  );
}
