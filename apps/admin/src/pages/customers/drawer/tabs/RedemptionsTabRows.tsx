import { cn } from '@/lib/utils';
import { Badge } from '@/components/ui/badge';
import { TableCell, TableHead, TableRow } from '@/components/ui/table';
import {
  COLUMN_DATE,
  COLUMN_ORDER,
  COLUMN_POINTS,
  COLUMN_REWARD,
  COLUMN_STATUS,
  COLUMN_VALUE,
  EMPTY_REDEMPTIONS_TITLE,
  emptyRedemptionsBody,
} from '../customer-drawer.copy';
import type { RedemptionRow } from '../customer-drawer.redemption-rows';
import { DRAWER_ROW_CLASS, drawerCell, DrawerTable } from './DrawerTable';

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
    <DrawerTable
      columnCount={6}
      isLoading={isLoading}
      isEmpty={rows.length === 0}
      emptyTitle={EMPTY_REDEMPTIONS_TITLE()}
      emptyBody={emptyRedemptionsBody(customerFirstName)}
      head={
        <TableRow>
          <TableHead>{COLUMN_DATE()}</TableHead>
          <TableHead>{COLUMN_REWARD()}</TableHead>
          <TableHead className="text-right">{COLUMN_VALUE()}</TableHead>
          <TableHead className="text-right">{COLUMN_POINTS()}</TableHead>
          <TableHead className="text-right">{COLUMN_ORDER()}</TableHead>
          <TableHead className="text-right">{COLUMN_STATUS()}</TableHead>
        </TableRow>
      }
    >
      {rows.map((row, index) => (
        <TableRow key={index} className={DRAWER_ROW_CLASS}>
          <TableCell className={drawerCell(1)}>{row.date}</TableCell>
          <TableCell className={drawerCell(2)}>{row.reward}</TableCell>
          <TableCell className={drawerCell(3, 'text-right')}>{row.value}</TableCell>
          <TableCell
            className={drawerCell(
              4,
              cn(
                'text-right tabular-nums',
                row.points.startsWith('-') && 'text-destructive',
                row.points.startsWith('+') && 'text-success',
              ),
            )}
          >
            {row.points}
          </TableCell>
          <TableCell className={drawerCell(5, 'text-muted-foreground text-right')}>{row.order}</TableCell>
          <TableCell className={drawerCell(6, 'text-right')}>
            <Badge variant="outline">{row.status}</Badge>
          </TableCell>
        </TableRow>
      ))}
    </DrawerTable>
  );
}
