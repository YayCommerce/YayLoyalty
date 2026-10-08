import { formatPoints } from '@/lib/helpers/points.helper';
import { DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/custom/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { BULK_HEADER_SUBTITLE, bulkHeaderTitle } from '../customers-adjust-points.bulk.copy';
import { ADJUST_POINTS_TITLE, singleCustomerHeader, staleHeaderSubtitle } from '../customers-adjust-points.copy';
import type { AdjustTarget } from '../customers-adjust-points.form-state';

/** Title + description: single-customer identity/balance (with a stale subtitle) or the bulk restatement. */
export function AdjustPointsHeader({
  isSingle,
  isLoading,
  singleCustomer,
  isStale,
  openedBalance,
  bulkEffectiveCount,
}: {
  isSingle: boolean;
  isLoading: boolean;
  singleCustomer: AdjustTarget | undefined;
  isStale: boolean;
  openedBalance: number | null;
  bulkEffectiveCount: number;
}) {
  function description() {
    if (isSingle && isLoading) return <Skeleton className="h-4 w-40" />;
    if (isSingle && singleCustomer) {
      const line = singleCustomerHeader(singleCustomer.name, formatPoints(singleCustomer.balance));
      return isStale && openedBalance !== null ? `${line} · ${staleHeaderSubtitle(formatPoints(openedBalance))}` : line;
    }
    return BULK_HEADER_SUBTITLE();
  }

  return (
    <DialogHeader>
      <DialogTitle>{isSingle ? ADJUST_POINTS_TITLE() : bulkHeaderTitle(bulkEffectiveCount)}</DialogTitle>
      <DialogDescription asChild>
        <div>{description()}</div>
      </DialogDescription>
    </DialogHeader>
  );
}
