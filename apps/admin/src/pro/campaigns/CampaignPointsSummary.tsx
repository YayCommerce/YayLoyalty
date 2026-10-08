import { __, sprintf } from '@wordpress/i18n';

import { formatPoints } from '@/lib/helpers/points.helper';
import { CampaignDisplayStatus } from '@/lib/schema/campaign.schema';
import { Card, CardContent } from '@/components/ui/card';
import { PointsValue } from '@/components/ui/custom/points-value';
import { Skeleton } from '@/components/ui/skeleton';

/**
 * Lifetime points-issued summary below CampaignTable. Ignores the status
 * filter: a global figure, not a view of the filtered list.
 */
export function CampaignPointsSummary({
  rows: allRows,
}: {
  rows: { id: number; name: string; displayStatus: CampaignDisplayStatus; points_issued: number }[];
}) {
  // Archived is retired, and a zero has nothing to bar-chart.
  const rows = allRows.filter((row) => row.displayStatus !== 'archived' && row.points_issued > 0);

  if (rows.length === 0) {
    return (
      <Card>
        <CardContent className="flex flex-col gap-1">
          <span className="text-sm font-semibold">{__('Points issued', 'yayloyalty')}</span>
          <span className="text-muted-foreground text-sm">{__('No activity', 'yayloyalty')}</span>
        </CardContent>
      </Card>
    );
  }

  const ranked = [...rows].sort((a, b) => b.points_issued - a.points_issued);
  const total = rows.reduce((sum, row) => sum + row.points_issued, 0);
  const max = Math.max(1, ...rows.map((row) => row.points_issued));

  return (
    <Card>
      <CardContent className="flex flex-col gap-3">
        <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
          <span className="text-sm font-semibold">{__('Points issued', 'yayloyalty')}</span>
          <span className="text-muted-foreground text-xs">
            {sprintf(
              /* translators: 1: total points issued, formatted; 2: number of campaigns. */
              __('%1$s points issued across %2$d campaigns', 'yayloyalty'),
              formatPoints(total),
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
                  style={{ width: `${(row.points_issued / max) * 100}%` }}
                />
              </div>
              {/* No dash case: `rows` already excludes anything without a positive figure. */}
              <span className="text-right font-semibold tabular-nums">
                <PointsValue value={row.points_issued} />
              </span>
            </div>
          ))}
        </div>
      </CardContent>
    </Card>
  );
}

/** The summary card's footprint while the list loads: title line plus one bar row. */
export function CampaignPointsSummarySkeleton() {
  return (
    <Card>
      <CardContent className="flex flex-col gap-3">
        <div className="flex items-baseline justify-between gap-3">
          <Skeleton className="h-4 w-24" />
          <Skeleton className="h-3 w-48" />
        </div>
        <div className="grid grid-cols-[8rem_1fr_auto] items-center gap-3 sm:grid-cols-[10rem_1fr_auto]">
          <Skeleton className="h-3 w-28" />
          <Skeleton className="h-2 w-full rounded-full" />
          <Skeleton className="h-3 w-10" />
        </div>
      </CardContent>
    </Card>
  );
}
