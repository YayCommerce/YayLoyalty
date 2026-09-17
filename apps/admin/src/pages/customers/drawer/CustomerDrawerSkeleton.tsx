import { Skeleton } from '@/components/ui/skeleton';

const STAT_COUNT = 6;
const ACTION_COUNT = 3;
/** Matches the real ledger page size (phase 06) — not the six rows 3b's
 *  artboard happened to draw, which assumed a six-row page. */
const LEDGER_ROW_COUNT = 10;

/**
 * 3b's loading state: identity (`CustomerDrawerHeader`) and, once built, the
 * tab bar are live immediately — only stats, actions and the first page of
 * ledger rows are placeholders here.
 */
export function CustomerDrawerSkeleton() {
  return (
    <div className="flex flex-col gap-5">
      <div className="grid grid-cols-[repeat(auto-fit,minmax(150px,1fr))] gap-3">
        {Array.from({ length: STAT_COUNT }, (_, index) => (
          <Skeleton key={index} className="h-20 rounded-lg" />
        ))}
      </div>
      <div className="flex flex-wrap gap-2">
        {Array.from({ length: ACTION_COUNT }, (_, index) => (
          <Skeleton key={index} className="h-9 w-32" />
        ))}
      </div>
      <div className="flex flex-col gap-2">
        {Array.from({ length: LEDGER_ROW_COUNT }, (_, index) => (
          <Skeleton key={index} className="h-10 w-full" />
        ))}
      </div>
    </div>
  );
}
