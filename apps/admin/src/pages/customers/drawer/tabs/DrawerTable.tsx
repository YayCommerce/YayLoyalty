import type { ReactNode } from 'react';

import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHeader, TableRow } from '@/components/ui/table';
import { DRAWER_PAGE_SIZE } from './DrawerTabPager';

/**
 * The shell every drawer tab table shares: header, skeleton rows while loading, an empty state, or the caller's rows.
 * Below `sm` rows wrap their cells two per line. A flexed `<tr>` does not stretch in Chromium,
 * so the table and body leave table layout there too (`block sm:table`), and the header is hidden.
 * `sm:min-w-max` lets the desktop table scroll rather than squeeze its cells.
 */
export function DrawerTable({
  head,
  columnCount,
  isLoading,
  isEmpty,
  emptyTitle,
  emptyBody,
  children,
}: {
  head: ReactNode;
  columnCount: number;
  isLoading: boolean;
  isEmpty: boolean;
  emptyTitle: string;
  emptyBody: string;
  children: ReactNode;
}) {
  return (
    <Table className="block sm:table sm:min-w-max">
      <TableHeader className="bg-muted/50 hidden sm:table-header-group">{head}</TableHeader>
      <TableBody className="block sm:table-row-group">
        {isLoading ? (
          Array.from({ length: DRAWER_PAGE_SIZE }, (_, index) => (
            <TableRow key={index}>
              <TableCell colSpan={columnCount}>
                <Skeleton className="h-5 w-full" />
              </TableCell>
            </TableRow>
          ))
        ) : isEmpty ? (
          <TableRow>
            <TableCell colSpan={columnCount} className="h-auto py-12 text-center whitespace-normal">
              <p className="text-sm font-medium">{emptyTitle}</p>
              <p className="text-muted-foreground mx-auto mt-1 max-w-sm text-sm">{emptyBody}</p>
            </TableCell>
          </TableRow>
        ) : (
          children
        )}
      </TableBody>
    </Table>
  );
}

/** A data row: stacks below `sm`, a real table row above. */
export const DRAWER_ROW_CLASS = 'flex flex-wrap sm:table-row';
