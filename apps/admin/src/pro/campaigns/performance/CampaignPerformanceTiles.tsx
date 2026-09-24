import { __, sprintf } from '@wordpress/i18n';

import { parseWPCurrency } from '@/lib/helpers/format.helper';
import { formatPoints } from '@/lib/helpers/points.helper';
import type { CampaignPerformance, CampaignPerformanceTrigger } from '@/lib/schema/campaign-performance.schema';
import { PointsValue } from '@/components/ui/custom/points-value';
import { StatTile, StatTileSkeleton } from '@/components/ui/custom/stat-tile';

type Totals = CampaignPerformance['totals'];

/** Purchase gets 4 tiles across `lg`; signup/review get 2 — same breakpoint both states use. */
function gridClass(trigger: CampaignPerformanceTrigger) {
  return trigger === 'purchase' ? 'grid grid-cols-2 gap-3 lg:grid-cols-4' : 'grid grid-cols-2 gap-3';
}

/**
 * Per-trigger tile set. `hasData` (not the raw counts) picks the empty-state
 * sublines — a real 0 (`orders_affected`/`customers_earned`) still renders as
 * a real count either way; only the sublines and the null-only fields
 * (`avg_points_per_order`) change wording. `'—'` (not a muted wrapper) for a
 * null value matches `DashboardStatsGrid`'s own liability/rate tiles.
 */
export function CampaignPerformanceTiles({
  trigger,
  totals,
  hasData,
}: {
  trigger: CampaignPerformanceTrigger;
  totals: Totals;
  hasData: boolean;
}) {
  return (
    <div className={gridClass(trigger)}>
      <StatTile
        label={__('Points issued', 'yayloyalty')}
        value={<PointsValue value={totals.points_issued} />}
        subline={__('by this campaign', 'yayloyalty')}
      />
      {trigger === 'purchase' && (
        <StatTile
          label={__('Orders affected', 'yayloyalty')}
          value={formatPoints(totals.orders_affected ?? 0)}
          subline={
            !hasData
              ? __('none yet', 'yayloyalty')
              : totals.orders_total === null
                ? // Unreachable today — the PHP contract sets orders_total whenever trigger is
                  // 'purchase' (the only case this tile renders), but the type is `nullable()`
                  // because the field is legitimately null for other triggers. This guards
                  // against that correlation ever drifting, not a real runtime case right now.
                  ''
                : sprintf(
                    /* translators: %s: total number of completed orders the store has ever had. */
                    __('of %s completed orders', 'yayloyalty'),
                    formatPoints(totals.orders_total),
                  )
          }
        />
      )}
      {trigger !== 'purchase' && (
        <StatTile
          label={__('Customers earned', 'yayloyalty')}
          value={formatPoints(totals.customers_earned ?? 0)}
          subline={hasData ? __('earned points from this campaign', 'yayloyalty') : __('none yet', 'yayloyalty')}
        />
      )}
      {trigger === 'purchase' && (
        <StatTile
          label={__('Average per order', 'yayloyalty')}
          value={totals.avg_points_per_order === null ? '—' : formatPoints(totals.avg_points_per_order)}
          subline={
            totals.avg_points_per_order === null
              ? __('needs at least one order', 'yayloyalty')
              : totals.avg_order_subtotal === null
                ? // avg_order_subtotal isn't computed yet (needs the earning engine's own scored
                  // base amount, not yet persisted) — say nothing rather than "$0.00 average order".
                  __('points per order', 'yayloyalty')
                : sprintf(
                    /* translators: %s: the average order subtotal, formatted as money. */
                    __('points · %s average order', 'yayloyalty'),
                    parseWPCurrency(totals.avg_order_subtotal),
                  )
          }
        />
      )}
      {trigger === 'purchase' && (
        <StatTile
          label={__('Cost in liability', 'yayloyalty')}
          value={totals.liability_amount === null ? '—' : parseWPCurrency(totals.liability_amount)}
          subline={
            totals.liability_above_default !== null && totals.liability_above_default > 0
              ? sprintf(
                  /* translators: %s: the portion of liability above what the default rate would have issued. */
                  __('%s of it above the default rate', 'yayloyalty'),
                  parseWPCurrency(totals.liability_above_default),
                )
              : __('nothing awarded', 'yayloyalty')
          }
        />
      )}
    </div>
  );
}

export function CampaignPerformanceTilesSkeleton({ trigger }: { trigger: CampaignPerformanceTrigger }) {
  const labels =
    trigger === 'purchase'
      ? [
          __('Points issued', 'yayloyalty'),
          __('Orders affected', 'yayloyalty'),
          __('Average per order', 'yayloyalty'),
          __('Cost in liability', 'yayloyalty'),
        ]
      : [__('Points issued', 'yayloyalty'), __('Customers earned', 'yayloyalty')];

  return (
    <div className={gridClass(trigger)}>
      {labels.map((label) => (
        <StatTileSkeleton key={label} label={label} />
      ))}
    </div>
  );
}
