import { __, sprintf } from '@wordpress/i18n';

import { parseWPCurrency } from '@/lib/helpers/format.helper';
import { formatPoints } from '@/lib/helpers/points.helper';
import type { CampaignPerformance } from '@/lib/schema/campaign-performance.schema';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { skipReasonLabel } from './campaign-performance.copy';

type Skipped = CampaignPerformance['skipped'];
type Estimate = CampaignPerformance['estimate'];

/**
 * Purchase-only "why not every order" / "what it would have earned before
 * real data" panel. Both fields are nullable in the contract (see
 * campaign-performance.schema.ts) so this panel can hide one section, or
 * itself entirely, without the caller branching on trigger.
 */
export function CampaignPerformanceSidePanel({ skipped, estimate }: { skipped: Skipped; estimate: Estimate }) {
  if (!skipped?.length && !estimate) {
    return null;
  }

  return (
    <div className="flex flex-col gap-4 lg:w-95 lg:shrink-0">
      {skipped && skipped.length > 0 && <SkippedCard skipped={skipped} />}
      {estimate && <EstimateCard estimate={estimate} />}
    </div>
  );
}

function SkippedCard({ skipped }: { skipped: NonNullable<Skipped> }) {
  const total = skipped.reduce((sum, skip) => sum + skip.count, 0);

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-sm">
          {sprintf(
            /* translators: %s: number of orders that earned nothing from this campaign. */
            __('Where the other %s orders went', 'yayloyalty'),
            formatPoints(total),
          )}
        </CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-2">
        {skipped.map((skip) => (
          <div key={skip.reason} className="flex gap-3 text-sm">
            <span className="w-9 shrink-0 text-right font-semibold tabular-nums">{formatPoints(skip.count)}</span>
            <span className="text-foreground">{skipReasonLabel(skip)}</span>
          </div>
        ))}
      </CardContent>
    </Card>
  );
}

function EstimateCard({ estimate }: { estimate: NonNullable<Estimate> }) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-sm">{__('What it would have done last year', 'yayloyalty')}</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-2">
        <p className="text-sm">{estimateSentence(estimate)}</p>
        <p className="text-muted-foreground text-xs">
          {__('An estimate from real orders. It disappears once this campaign has real figures.', 'yayloyalty')}
        </p>
      </CardContent>
    </Card>
  );
}

/** Two full sentences, not one spliced together — dropping the liability clause mid-sentence would leave a translator half a sentence to work with. */
function estimateSentence(estimate: NonNullable<Estimate>): string {
  const year = estimate.from.slice(0, 4);

  if (estimate.liability_amount === null) {
    return sprintf(
      /* translators: 1: number of orders in the same week last year, 2: that year, 3: estimated points. */
      __(
        'Based on the %1$s orders in the same week of %2$s, this campaign would have issued about %3$s points.',
        'yayloyalty',
      ),
      formatPoints(estimate.orders),
      year,
      formatPoints(estimate.points),
    );
  }

  return sprintf(
    /* translators: 1: number of orders in the same week last year, 2: that year, 3: estimated points, 4: estimated liability, formatted as money. */
    __(
      'Based on the %1$s orders in the same week of %2$s, this campaign would have issued about %3$s points — roughly %4$s of liability.',
      'yayloyalty',
    ),
    formatPoints(estimate.orders),
    year,
    formatPoints(estimate.points),
    parseWPCurrency(estimate.liability_amount),
  );
}

/**
 * One generic card, since which of `skipped`/`estimate` is set is unknown until the summary loads.
 * No caller today: the server sends both as `null`, so the page skeleton leaves this out. Kept for
 * when they carry real data.
 */
export function CampaignPerformanceSidePanelSkeleton() {
  return (
    <div className="flex flex-col gap-4 lg:w-95 lg:shrink-0">
      <Card>
        <CardHeader>
          <Skeleton className="h-4 w-48" />
        </CardHeader>
        <CardContent className="flex flex-col gap-3">
          <Skeleton className="h-3 w-full" />
          <Skeleton className="h-3 w-full" />
          <Skeleton className="h-3 w-2/3" />
        </CardContent>
      </Card>
    </div>
  );
}
