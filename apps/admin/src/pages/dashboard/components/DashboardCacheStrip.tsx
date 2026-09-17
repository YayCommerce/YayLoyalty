import { __ } from '@wordpress/i18n';

import { Button } from '@/components/ui/button';
import { cacheStripTimes } from '../dashboard-page.copy';

/** Says the figures are cached, with last/next times; rendered only when `cached` is true. */
export function DashboardCacheStrip({
  generatedAt,
  nextRefreshAt,
  onRefresh,
  isRefreshing,
}: {
  generatedAt: string;
  nextRefreshAt: string | null;
  onRefresh: () => void;
  isRefreshing: boolean;
}) {
  return (
    <div className="bg-muted text-muted-foreground flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md px-3 py-2 text-xs">
      <span>{__('Figures for a store this size are computed periodically.', 'yayloyalty')}</span>
      <span className="tabular-nums">{cacheStripTimes(generatedAt, nextRefreshAt)}</span>
      <Button variant="link" size="sm" className="h-auto p-0 text-xs" onClick={onRefresh} disabled={isRefreshing}>
        {isRefreshing ? __('Refreshing…', 'yayloyalty') : __('Refresh now', 'yayloyalty')}
      </Button>
    </div>
  );
}
