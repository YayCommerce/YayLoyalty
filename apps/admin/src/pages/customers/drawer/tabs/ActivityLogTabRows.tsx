import { Mail } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import type { ActivityRow } from '../customer-drawer.activity-rows';
import {
  COLUMN_BY,
  COLUMN_DATE,
  COLUMN_EVENT,
  EMPTY_ACTIVITY_BODY,
  EMPTY_ACTIVITY_TITLE,
  STAFF_ONLY_LABEL,
} from '../customer-drawer.copy';

const COLUMN_COUNT = 3;
const SKELETON_ROW_COUNT = 10;

/**
 * Date+time / Event (title + sub-line) / By, with the `STAFF ONLY` badge on
 * the two note-carrying event types and an envelope glyph on email rows
 * (3d). Below `sm`: same `flex-wrap` + `order-*` stacking `LedgerTabRows.tsx`
 * uses, not a second technique. No `customerFirstName` prop — unlike the
 * ledger's empty state, `EMPTY_ACTIVITY_BODY()` has no name slot.
 */
export function ActivityLogTabRows({ rows, isLoading }: { rows: ActivityRow[]; isLoading: boolean }) {
  return (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>{COLUMN_DATE()}</TableHead>
          <TableHead>{COLUMN_EVENT()}</TableHead>
          <TableHead className="text-right">{COLUMN_BY()}</TableHead>
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
              <p className="text-sm font-medium">{EMPTY_ACTIVITY_TITLE()}</p>
              <p className="text-muted-foreground mx-auto mt-1 max-w-sm text-sm">{EMPTY_ACTIVITY_BODY()}</p>
            </TableCell>
          </TableRow>
        ) : (
          rows.map((row, index) => (
            <TableRow key={index} className="flex flex-wrap sm:table-row">
              <TableCell className="order-1 w-1/2 sm:order-0 sm:w-auto sm:table-cell">
                <span className="flex flex-col gap-0.5">
                  <span className="text-muted-foreground">{row.date}</span>
                  <span className="text-muted-foreground text-xs">{row.time}</span>
                </span>
              </TableCell>
              <TableCell className="order-2 w-full sm:order-0 sm:w-auto sm:table-cell">
                <span className="flex flex-col gap-0.5">
                  <span>{row.title}</span>
                  <span className="text-muted-foreground text-xs">{row.subLine}</span>
                </span>
              </TableCell>
              <TableCell className="order-3 w-full text-right sm:order-0 sm:w-auto sm:table-cell">
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
          ))
        )}
      </TableBody>
    </Table>
  );
}
