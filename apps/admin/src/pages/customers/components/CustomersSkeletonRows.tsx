import { Skeleton } from '@/components/ui/skeleton';
import { TableCell, TableRow } from '@/components/ui/table';

/**
 * 8 placeholder rows at full row height (artboard 2e) — the row does not
 * resize once real data lands. Two skeleton lines in the Customer cell match
 * the name+email two-line pattern; every other cell is a single bar.
 */
export function CustomersSkeletonRows({ columnCount }: { columnCount: number }) {
  return (
    <>
      {Array.from({ length: 8 }).map((_, index) => (
        <TableRow key={index}>
          <TableCell colSpan={columnCount}>
            <div className="flex items-center gap-6">
              <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                <Skeleton className="h-3 w-36" />
                <Skeleton className="h-2.5 w-48" />
              </div>
              <Skeleton className="h-3 w-14" />
              <Skeleton className="h-3 w-10" />
              <Skeleton className="h-3 w-16" />
              <Skeleton className="h-3 w-20" />
              <Skeleton className="h-3 w-20" />
            </div>
          </TableCell>
        </TableRow>
      ))}
    </>
  );
}
