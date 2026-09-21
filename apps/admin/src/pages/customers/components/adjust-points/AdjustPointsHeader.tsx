import { formatPoints } from '@/lib/helpers/points.helper';
import type { AdjustPointsPreviewCustomer } from '@/lib/schema/customer.schema';
import { DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/custom/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { bulkHeaderTitle, BULK_HEADER_SUBTITLE } from '../customers-adjust-points.bulk.copy';
import { ADJUST_POINTS_TITLE, singleCustomerHeader, staleHeaderSubtitle } from '../customers-adjust-points.copy';
import { pointsToMoney } from '../../drawer/customer-drawer.money';

/** Title + description: single-customer identity/balance (with a stale subtitle) or the bulk restatement. */
export function AdjustPointsHeader({
  isSingle,
  isLoading,
  singleCustomer,
  pointsPerCurrencyUnit,
  isStale,
  openedBalance,
  bulkEffectiveCount,
}: {
  isSingle: boolean;
  isLoading: boolean;
  singleCustomer: AdjustPointsPreviewCustomer | undefined;
  pointsPerCurrencyUnit: number;
  isStale: boolean;
  openedBalance: number | null;
  bulkEffectiveCount: number;
}) {
  function description() {
    if (isSingle && isLoading) return <Skeleton className="h-4 w-40" />;
    if (isSingle && singleCustomer) {
      const money = pointsToMoney(singleCustomer.balance, pointsPerCurrencyUnit);
      const line = singleCustomerHeader(singleCustomer.name, formatPoints(singleCustomer.balance), money);
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
