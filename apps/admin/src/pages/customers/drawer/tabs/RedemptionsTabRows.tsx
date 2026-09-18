import { cn } from '@/lib/utils';
import { Badge } from '@/components/ui/badge';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import type { RedemptionRow } from '../customer-drawer.redemption-rows';
import {
  COLUMN_DATE,
  COLUMN_ORDER,
  COLUMN_REWARD,
  COLUMN_STATUS,
  COLUMN_VALUE,
  COLUMN_POINTS,
  EMPTY_REDEMPTIONS_TITLE,
  emptyRedemptionsBody,
} from '../customer-drawer.copy';

const COLUMN_COUNT = 6;
const SKELETON_ROW_COUNT = 10;

/**
 * 3e's six columns — Date / Reward / Value / Points / Order / Status. Same
 * `flex-wrap` + `order-*` below-`sm` stacking `LedgerTabRows.tsx` uses, not
 * a second technique — six columns need it even more than the ledger's four.
 */
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
    <Table className="min-w-max">
      <TableHeader>
        <TableRow>
          <TableHead>{COLUMN_DATE()}</TableHead>
          <TableHead>{COLUMN_REWARD()}</TableHead>
          <TableHead className="text-right">{COLUMN_VALUE()}</TableHead>
          <TableHead className="text-right">{COLUMN_POINTS()}</TableHead>
          <TableHead className="text-right">{COLUMN_ORDER()}</TableHead>
          <TableHead className="text-right">{COLUMN_STATUS()}</TableHead>
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
              <p className="text-sm font-medium">{EMPTY_REDEMPTIONS_TITLE()}</p>
              <p className="text-muted-foreground mx-auto mt-1 max-w-sm text-sm">
                {emptyRedemptionsBody(customerFirstName)}
              </p>
            </TableCell>
          </TableRow>
        ) : (
          rows.map((row, index) => (
            <TableRow key={index} className="flex flex-wrap sm:table-row">
              <TableCell className="order-1 w-1/2 sm:order-0 sm:w-auto sm:table-cell">{row.date}</TableCell>
              <TableCell className="order-2 w-1/2 sm:order-0 sm:w-auto sm:table-cell">{row.reward}</TableCell>
              <TableCell className="order-3 w-1/2 text-right sm:order-0 sm:w-auto sm:table-cell">
                {row.value}
              </TableCell>
              <TableCell
                className={cn(
                  'order-4 w-1/2 text-right tabular-nums sm:order-0 sm:w-auto sm:table-cell',
                  row.points.startsWith('-') && 'text-destructive',
                  row.points.startsWith('+') && 'text-success',
                )}
              >
                {row.points}
              </TableCell>
              <TableCell className="text-muted-foreground order-5 w-1/2 text-right sm:order-0 sm:w-auto sm:table-cell">
                {row.order}
              </TableCell>
              <TableCell className="order-6 w-1/2 text-right sm:order-0 sm:w-auto sm:table-cell">
                <Badge variant="outline">{row.status}</Badge>
              </TableCell>
            </TableRow>
          ))
        )}
      </TableBody>
    </Table>
  );
}
