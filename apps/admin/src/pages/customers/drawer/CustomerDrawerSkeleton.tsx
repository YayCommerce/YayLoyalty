import { Skeleton } from '@/components/ui/skeleton';
import { DRAWER_PAGE_SIZE } from './tabs/DrawerTabPager';

const STAT_COUNT = 5;
const ACTION_COUNT = 3;

/**
 * 3b's loading state: identity (`CustomerDrawerHeader`) and, once built, the
 * tab bar are live immediately — only stats, actions and the first page of
 * ledger rows are placeholders here.
 */
export function CustomerDrawerSkeleton() {
  return (
    <div className="flex flex-col gap-5">
      {/* Label bar over value bar, matching the borderless stat cells the
          real grid renders — a single block would flash a card that never
          appears once the data lands. */}
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
