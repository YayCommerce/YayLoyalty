import { TriangleAlert } from 'lucide-react';

import { formatPoints } from '@/lib/helpers/points.helper';
import { Button } from '@/components/ui/button';
import {
  RELOAD_BALANCE_LABEL,
  staleBannerBody,
  staleBannerTitle,
  STALE_BLOCKS_SAVING,
  STALE_KEEPS_VALUES,
} from '../customers-adjust-points.copy';

/** The balance moved since the dialog opened. Saving stays blocked until `onReload` succeeds and re-baselines. */
export function AdjustPointsStaleBalanceBanner({
  customerName,
  openedBalance,
  latestBalance,
  onReload,
  isReloading,
}: {
  customerName: string;
  openedBalance: number;
  latestBalance: number;
  onReload: () => void;
  isReloading: boolean;
}) {
  return (
    <div className="flex flex-col gap-2 rounded-md border px-3.5 py-3">
      <div className="flex items-start gap-2">
        <TriangleAlert className="text-muted-foreground mt-0.5 size-4 shrink-0" />
        <div className="flex flex-col gap-1">
          <p className="text-sm font-medium">{staleBannerTitle(customerName)}</p>
          <p className="text-muted-foreground text-xs">
            {staleBannerBody(formatPoints(latestBalance), formatPoints(openedBalance))}
          </p>
          <p className="text-muted-foreground text-xs">{STALE_BLOCKS_SAVING()}</p>
        </div>
      </div>
      <div className="flex items-center justify-between gap-2 ps-6">
        <Button variant="outline" size="sm" onClick={onReload} disabled={isReloading}>
          {RELOAD_BALANCE_LABEL()}
        </Button>
        <span className="text-muted-foreground text-xs">{STALE_KEEPS_VALUES()}</span>
      </div>
    </div>
  );
}
