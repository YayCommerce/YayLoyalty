import { __, sprintf } from '@wordpress/i18n';

import { CampaignDisplayStatus } from '@/lib/schema/campaign.schema';
import { Card, CardContent } from '@/components/ui/card';
import { PointsValue } from '@/components/ui/custom/points-value';
import { hasPointsFigure } from './campaign-points-dummy';

/**
 * "Last 30 days" summary below CampaignTable. Ignores the status filter: a
 * global figure, not a view of the filtered list. pointsLast30Days is dummy.
 */
export function CampaignPointsSummary({
  rows: allRows,
}: {
  rows: { id: number; name: string; displayStatus: CampaignDisplayStatus; pointsLast30Days: number | null }[];
}) {
  // Archived is retired, and a zero has nothing to bar-chart.
  const rows = allRows.filter(
    (row) => row.displayStatus !== 'archived' && hasPointsFigure(row.displayStatus) && (row.pointsLast30Days ?? 0) > 0,
  );

  if (rows.length === 0) {
    return (
      <Card>
        <CardContent className="flex flex-col gap-1">
          <span className="text-sm font-semibold">{__('Last 30 days', 'yayloyalty')}</span>
          <span className="text-muted-foreground text-sm">{__('No activity', 'yayloyalty')}</span>
        </CardContent>
      </Card>
    );
  }

  const ranked = [...rows].sort((a, b) => (b.pointsLast30Days ?? -1) - (a.pointsLast30Days ?? -1));
  const total = rows.reduce((sum, row) => sum + (row.pointsLast30Days ?? 0), 0);
  const max = Math.max(1, ...rows.map((row) => row.pointsLast30Days ?? 0));

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
                  style={{ width: `${((row.pointsLast30Days ?? 0) / max) * 100}%` }}
                />
              </div>
              {/* No dash branch needed here — `rows` above already excludes anything without a real, positive figure. */}
              <span className="text-right font-semibold tabular-nums">
                <PointsValue value={row.pointsLast30Days ?? 0} />
              </span>
            </div>
          ))}
        </div>
      </CardContent>
    </Card>
  );
}
