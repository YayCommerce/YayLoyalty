import { Mail } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { TableCell, TableHead, TableRow } from '@/components/ui/table';
import type { ActivityRow } from '../customer-drawer.activity-rows';
import { COLUMN_BY, COLUMN_EVENT, STAFF_ONLY_LABEL } from '../customer-drawer.activity.copy';
import { COLUMN_DATE, EMPTY_ACTIVITY_BODY, EMPTY_ACTIVITY_TITLE } from '../customer-drawer.copy';
import { DRAWER_ROW_CLASS, drawerCell, DrawerTable } from './DrawerTable';

/**
 * Date+time / Event (title + sub-line) / By, with the `STAFF ONLY` badge on
 * the two note-carrying event types and an envelope glyph on email rows (3d).
 */
export function ActivityLogTabRows({ rows, isLoading }: { rows: ActivityRow[]; isLoading: boolean }) {
  return (
    <DrawerTable
      columnCount={3}
      isLoading={isLoading}
      isEmpty={rows.length === 0}
      emptyTitle={EMPTY_ACTIVITY_TITLE()}
      emptyBody={EMPTY_ACTIVITY_BODY()}
      head={
        <TableRow>
          <TableHead>{COLUMN_DATE()}</TableHead>
          <TableHead>{COLUMN_EVENT()}</TableHead>
          <TableHead className="text-right">{COLUMN_BY()}</TableHead>
        </TableRow>
      }
    >
      {rows.map((row, index) => (
        <TableRow key={index} className={DRAWER_ROW_CLASS}>
          <TableCell className={drawerCell(1)}>
            <span className="flex flex-col gap-0.5">
              <span className="text-muted-foreground">{row.date}</span>
              <span className="text-muted-foreground text-xs">{row.time}</span>
            </span>
          </TableCell>
          <TableCell className={drawerCell(2, 'w-full')}>
            <span className="flex flex-col gap-0.5">
              <span>{row.title}</span>
              <span className="text-muted-foreground text-xs">{row.subLine}</span>
            </span>
          </TableCell>
          <TableCell className={drawerCell(3, 'w-full text-right')}>
            <span className="flex items-center justify-end gap-1.5">
              {row.isEmail && <Mail className="text-muted-foreground size-3.5" />}
              {row.isStaffOnly && (
                <Badge variant="outline" className="text-[10px] tracking-wide">
                  {STAFF_ONLY_LABEL()}
                </Badge>
              )}
              <span className="text-muted-foreground text-xs">{row.actorLabel}</span>
            </span>
          </TableCell>
        </TableRow>
      ))}
    </DrawerTable>
  );
}
