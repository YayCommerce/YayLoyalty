import { __, _n, sprintf } from '@wordpress/i18n';

import { CampaignDisplayStatus } from '@/lib/schema/campaign.schema';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { PointsValue } from '@/components/ui/custom/points-value';
import { Skeleton } from '@/components/ui/skeleton';

/** Rows beyond this fold into one "N more campaigns" row. */
const VISIBLE_ROWS = 5;

/**
 * Lifetime points-issued summary below CampaignTable. Ignores the status
 * filter: a global figure, not a view of the filtered list.
 */
export function CampaignPointsSummary({
  rows: allRows,
}: {
  rows: { id: number; name: string; displayStatus: CampaignDisplayStatus; points_issued: number }[];
}) {
  // Archived is retired, and a zero has no share to show.
  const rows = allRows.filter((row) => row.displayStatus !== 'archived' && row.points_issued > 0);

  if (rows.length === 0) {
    return (
      <Card>
        <PointsSummaryHeader />
        <CardContent>
          <span className="text-muted-foreground text-sm">
            {__('No campaign has issued points yet.', 'yayloyalty')}
          </span>
        </CardContent>
      </Card>
    );
  }

  const ranked = [...rows].sort((a, b) => b.points_issued - a.points_issued);
  const total = rows.reduce((sum, row) => sum + row.points_issued, 0);
  const visible = ranked.slice(0, VISIBLE_ROWS);
  const rest = ranked.slice(VISIBLE_ROWS);
  const restPoints = rest.reduce((sum, row) => sum + row.points_issued, 0);

  return (
    <Card>
      <PointsSummaryHeader />
      <CardContent className="grid gap-6 sm:grid-cols-[14rem_1fr]">
        <div className="flex flex-col gap-1">
          <PointsValue value={total} className="text-3xl leading-none font-semibold tabular-nums" />
          <span className="text-muted-foreground text-sm">
            {sprintf(
              /* translators: %d: number of campaigns that issued points. */
              _n('points across %d campaign', 'points across %d campaigns', rows.length, 'yayloyalty'),
              rows.length,
            )}
          </span>
        </div>

        <ul className="flex min-w-0 flex-col gap-3">
          {visible.map((row) => (
            <li key={row.id} className="flex flex-col gap-1.5">
              <ShareLine label={row.name} points={row.points_issued} total={total} />
              {/* Share of the total, not of the leader, so the bar always agrees with the %. */}
              <div className="bg-muted h-1.5 overflow-hidden rounded-full">
                <div
                  className="bg-primary h-full rounded-full"
                  style={{ width: `${(row.points_issued / total) * 100}%` }}
                />
              </div>
            </li>
          ))}
          {rest.length > 0 && (
            <li className="text-muted-foreground">
              <ShareLine
                label={sprintf(
                  /* translators: %d: number of campaigns not listed individually. */
                  _n('%d more campaign', '%d more campaigns', rest.length, 'yayloyalty'),
                  rest.length,
                )}
                points={restPoints}
                total={total}
              />
            </li>
          )}
        </ul>
      </CardContent>
    </Card>
  );
}

function PointsSummaryHeader() {
  return (
    <CardHeader>
      <CardTitle>{__('Points issued', 'yayloyalty')}</CardTitle>
      <CardDescription>{__('Lifetime, archived campaigns excluded', 'yayloyalty')}</CardDescription>
    </CardHeader>
  );
}

/** Name, points and share of the total on one line, the numbers in fixed columns. */
function ShareLine({ label, points, total }: { label: string; points: number; total: number }) {
  return (
    <div className="flex items-baseline gap-3 text-sm">
      <span className="min-w-0 flex-1 truncate font-medium">{label}</span>
      <PointsValue value={points} className="tabular-nums" />
      <span className="text-muted-foreground w-10 text-right tabular-nums">{formatShare(points / total)}</span>
    </div>
  );
}

function formatShare(share: number) {
  const percent = Math.round(share * 100);

  return percent === 0 ? '<1%' : `${percent}%`;
}

/** The summary card's footprint while the list loads: header, total block and two rows. */
export function CampaignPointsSummarySkeleton() {
  return (
    <Card>
      <CardHeader>
        <Skeleton className="h-5 w-28" />
        <Skeleton className="h-4 w-56" />
      </CardHeader>
      <CardContent className="grid gap-6 sm:grid-cols-[14rem_1fr]">
        <div className="flex flex-col gap-2">
          <Skeleton className="h-8 w-20" />
          <Skeleton className="h-4 w-36" />
        </div>
        <div className="flex flex-col gap-3">
          {[0, 1].map((i) => (
            <div key={i} className="flex flex-col gap-1.5">
              <div className="flex items-baseline gap-3">
                <Skeleton className="h-4 flex-1" />
                <Skeleton className="h-4 w-10" />
                <Skeleton className="h-4 w-10" />
              </div>
              <Skeleton className="h-1.5 w-full rounded-full" />
            </div>
          ))}
        </div>
      </CardContent>
    </Card>
  );
}
