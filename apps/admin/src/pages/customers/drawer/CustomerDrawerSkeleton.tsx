import { Skeleton } from '@/components/ui/skeleton';
import { DRAWER_PAGE_SIZE } from './tabs/DrawerTabPager';

const HERO_STAT_COUNT = 2;
const STRIP_STAT_COUNT = 3;
const ACTION_COUNT = 2;

/** Placeholders for the stats, the actions and the first page of ledger rows. The header renders on its own. */
export function CustomerDrawerSkeleton() {
  return (
    <div className="flex flex-col gap-5">
      {/* The stats card's shape: two headline cells over a strip of three. */}
      <div className="rounded-md border">
        <div className="grid grid-cols-2 gap-4 p-4">
          {Array.from({ length: HERO_STAT_COUNT }, (_, index) => (
            <div key={index} className="flex flex-col gap-1.5">
              <Skeleton className="h-3 w-20" />
              <Skeleton className="h-7 w-24" />
            </div>
          ))}
        </div>
        <div className="grid grid-cols-3 divide-x border-t">
          {Array.from({ length: STRIP_STAT_COUNT }, (_, index) => (
            <div key={index} className="flex flex-col gap-1.5 px-4 py-3">
              <Skeleton className="h-3 w-16" />
              <Skeleton className="h-4 w-20" />
            </div>
          ))}
        </div>
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
