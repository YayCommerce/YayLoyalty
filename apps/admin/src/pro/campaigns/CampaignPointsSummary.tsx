import { __, sprintf } from '@wordpress/i18n';

import { Card, CardContent } from '@/components/ui/card';
import { YayLoyaltyToolTip } from '@/components/ui/custom/tooltip';
import { formatPoints, formatPointsExact, isPointsAbbreviated, type CampaignTableRow } from './campaign-table.api';

/**
 * "Last 30 days" points-issued summary, below CampaignTable — reads rows
 * already in memory, not a separate fetch. Ignores the table's status
 * filter on purpose: it's a global figure, not a view of the filtered list,
 * so it ranks everything handed to it, archived rows aside.
 */
export function CampaignPointsSummary({ rows: allRows }: { rows: CampaignTableRow[] }) {
  // Archived campaigns are retired — this figure shouldn't resurface something a merchant put away.
  const rows = allRows.filter((row) => row.display_status !== 'archived');

  if (rows.length === 0) {
    return null;
  }

  const ranked = [...rows].sort((a, b) => (b.points_last_30_days ?? -1) - (a.points_last_30_days ?? -1));
  const total = rows.reduce((sum, row) => sum + (row.points_last_30_days ?? 0), 0);
  // At least 1 so a fresh install (every figure null) divides to 0% bars instead of NaN.
  const max = Math.max(1, ...rows.map((row) => row.points_last_30_days ?? 0));

  return (
    <Card>
      <CardContent className="flex flex-col gap-3">
        <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
          <span className="text-sm font-semibold">{__('Last 30 days', 'yayloyalty')}</span>
          <span className="text-muted-foreground text-xs">
            {sprintf(
              /* translators: 1: total points issued in the window, formatted; 2: number of campaigns. */
              __('%1$s points issued across %2$d campaigns', 'yayloyalty'),
              total.toLocaleString(),
              rows.length,
            )}
          </span>
        </div>

        <div className="flex flex-col gap-2">
          {ranked.map((row) => (
            <div
              key={row.id}
              className="grid grid-cols-[8rem_1fr_auto] items-center gap-3 text-xs sm:grid-cols-[10rem_1fr_auto]"
            >
              <span className="truncate">{row.name}</span>
              <div className="bg-muted h-2 overflow-hidden rounded-full">
                <div
                  className="bg-primary h-full rounded-full"
                  style={{ width: `${((row.points_last_30_days ?? 0) / max) * 100}%` }}
                />
              </div>
              <span className="text-right font-semibold tabular-nums">
                {isPointsAbbreviated(row.points_last_30_days, row.display_status) ? (
                  <YayLoyaltyToolTip
                    trigger={<span>{formatPoints(row.points_last_30_days, row.display_status)}</span>}
                    content={formatPointsExact(row.points_last_30_days, row.display_status)}
                  />
                ) : (
                  formatPoints(row.points_last_30_days, row.display_status)
                )}
              </span>
            </div>
          ))}
        </div>
      </CardContent>
    </Card>
  );
}
