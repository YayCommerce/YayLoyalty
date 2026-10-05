import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';
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
      <TableHeader className="hidden sm:table-header-group">{head}</TableHeader>
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

// Full literals so Tailwind's scanner sees every class; `order-${n}` would be dropped.
const CELL_ORDER = {
  1: 'order-1',
  2: 'order-2',
  3: 'order-3',
  4: 'order-4',
  5: 'order-5',
  6: 'order-6',
} as const;

/** A cell that is half a line below `sm`, in `order` position, and a normal cell above. `extra` can override as well as add. */
export function drawerCell(order: keyof typeof CELL_ORDER, extra?: string): string {
  return cn(
    CELL_ORDER[order],
    'w-1/2 whitespace-normal sm:order-0 sm:table-cell sm:w-auto sm:whitespace-nowrap',
    extra,
  );
}
