import { Skeleton } from '@/components/ui/skeleton';
import { DRAWER_PAGE_SIZE } from './tabs/DrawerTabPager';

const STAT_COUNT = 4;

/** Placeholders for the overview, the history toolbar and the first page of ledger rows. The header renders on its own. */
export function CustomerDrawerSkeleton() {
  return (
    <div className="flex flex-col gap-5">
      <div className="rounded-lg border">
        <div className="border-b px-4 py-3">
          <Skeleton className="h-4 w-32" />
        </div>
        <div className="grid grid-cols-2 sm:grid-cols-4 sm:divide-x">
          {Array.from({ length: STAT_COUNT }, (_, index) => (
            <div key={index} className="flex flex-col gap-1.5 p-4">
              <Skeleton className="h-3 w-20" />
              <Skeleton className="h-7 w-16" />
            </div>
          ))}
        </div>
      </div>
      <div className="flex flex-col gap-2">
        {Array.from({ length: DRAWER_PAGE_SIZE }, (_, index) => (
          <Skeleton key={index} className="h-10 w-full" />
        ))}
      </div>
    </div>
  );
}
