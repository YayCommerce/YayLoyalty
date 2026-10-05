import { Skeleton } from '@/components/ui/skeleton';
import { DRAWER_PAGE_SIZE } from './tabs/DrawerTabPager';

const STAT_COUNT = 5;
const ACTION_COUNT = 3;

/** Placeholders for the stats, the actions and the first page of ledger rows. The header renders on its own. */
export function CustomerDrawerSkeleton() {
  return (
    <div className="flex flex-col gap-5">
      {/* Label bar over value bar, like the borderless stat cells: one block would flash a card that never appears. */}
      <div className="grid grid-cols-[repeat(auto-fit,minmax(150px,1fr))] gap-x-4 gap-y-3">
        {Array.from({ length: STAT_COUNT }, (_, index) => (
          <div key={index} className="flex flex-col gap-1.5">
            <Skeleton className="h-3 w-20" />
            <Skeleton className="h-6 w-24" />
          </div>
        ))}
      </div>
      <div className="flex flex-wrap gap-2">
        {Array.from({ length: ACTION_COUNT }, (_, index) => (
          <Skeleton key={index} className="h-9 w-32" />
        ))}
      </div>
      <div className="flex flex-col gap-2">
        {Array.from({ length: DRAWER_PAGE_SIZE }, (_, index) => (
          <Skeleton key={index} className="h-10 w-full" />
        ))}
      </div>
    </div>
  );
}
