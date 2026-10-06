import { formatPoints, formatPointsAsMoney } from '@/lib/helpers/points.helper';
import { Checkbox } from '@/components/ui/checkbox';
import { Skeleton } from '@/components/ui/skeleton';
import { BALANCE_RANGE_OPTIONS, EXPIRING_SOON_OPTIONS, type RowFilters } from '../../customers-page.filter-rows';
import { MAX_MATCHING_ROWS } from '../../customers-page.matching-customers';
import {
  acknowledgeLiability,
  bulkFilterLine,
  bulkLiabilityRange,
  bulkLiabilityReducesUpTo,
  bulkNotShownOnPage,
  bulkSelectionCount,
  bulkTooLargeSelection,
  bulkTotalPointsLine,
  bulkTotalsLine,
  NO_UNDO_TITLE,
  noUndoBody,
  writtenToLedgers,
} from '../customers-adjust-points.bulk.copy';
import { addsToLiability, POINTS_UNIT } from '../customers-adjust-points.copy';
import { bulkLiabilityProjection, bulkTotalPoints, type AdjustFormState } from '../customers-adjust-points.form-state';

/** The active filters as text. */
function filterSummary(filters: Pick<RowFilters, 'search' | 'balanceRange' | 'expiringSoon'>): string | null {
  const parts: string[] = [];

  if (filters.search.trim()) parts.push(`"${filters.search.trim()}"`);

  const balanceLabel = BALANCE_RANGE_OPTIONS.find((option) => option.value === filters.balanceRange)?.label;
  if (filters.balanceRange !== 'any' && balanceLabel) parts.push(balanceLabel);

  const expiringLabel = EXPIRING_SOON_OPTIONS.find((option) => option.value === filters.expiringSoon)?.label;
  if (filters.expiringSoon !== 'off' && expiringLabel) parts.push(expiringLabel);

  return parts.length > 0 ? parts.join(' · ') : null;
}

/** Bulk-only: the selection summary, the totals, and the no-undo notice with its acknowledgement. */
export function AdjustPointsBulkPanels({
  state,
  effectiveCount,
  isCountLoading,
  selectionMode,
  filters,
  notShownOnPage,
  pointsPerCurrencyUnit,
  liabilityPoints,
  hasPreviewError,
  isOverLimit,
  acknowledged,
  onAcknowledgedChange,
}: {
  state: Pick<AdjustFormState, 'direction' | 'amount'>;
  effectiveCount: number;
  isCountLoading: boolean;
  selectionMode: 'partial' | 'all';
  filters: RowFilters;
  /** Rows the filter matches beyond the current page; 0 when none. */
  notShownOnPage: number;
  pointsPerCurrencyUnit: number;
  liabilityPoints: number | null;
  /** The preview errored: `liabilityPoints` can't be trusted, so the liability line is hidden rather than guessed at. */
  hasPreviewError: boolean;
  isOverLimit: boolean;
  acknowledged: boolean;
  onAcknowledgedChange: (checked: boolean) => void;
}) {
  const summary = selectionMode === 'all' ? filterSummary(filters) : null;
  const total = bulkTotalPoints(state, effectiveCount);
  const totalMoney = formatPointsAsMoney(total, pointsPerCurrencyUnit);
  const projection = bulkLiabilityProjection(state.direction, total, liabilityPoints);

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-col gap-1">
        <div className="text-sm font-medium">
          {isCountLoading ? <Skeleton className="h-4 w-28" /> : bulkSelectionCount(effectiveCount)}
        </div>
        {summary && <p className="text-muted-foreground text-xs">{bulkFilterLine(summary)}</p>}
        {selectionMode === 'all' && notShownOnPage > 0 && (
          <p className="text-muted-foreground text-xs">{bulkNotShownOnPage(notShownOnPage)}</p>
        )}
        {isOverLimit && <p className="text-destructive text-xs">{bulkTooLargeSelection(MAX_MATCHING_ROWS)}</p>}
      </div>

      {state.amount > 0 && !isOverLimit && (
        <div className="flex flex-col gap-1">
          <p className="text-sm font-medium">{bulkTotalsLine(effectiveCount, formatPoints(state.amount))}</p>
          <p className="text-sm">{bulkTotalPointsLine(formatPoints(total), totalMoney)}</p>
          {!hasPreviewError && liabilityLine(projection, state.direction, pointsPerCurrencyUnit)}
        </div>
      )}

      <div className="flex flex-col gap-2 rounded-md border px-3.5 py-3 text-xs">
        <p className="text-sm font-medium">{NO_UNDO_TITLE()}</p>
        <p className="text-muted-foreground">{noUndoBody(effectiveCount)}</p>
        <p className="text-muted-foreground">{writtenToLedgers(effectiveCount)}</p>

        {state.amount > 0 && !isOverLimit && (
          <label className="flex items-start gap-2.5 pt-1">
            <Checkbox checked={acknowledged} onCheckedChange={(checked) => onAcknowledgedChange(checked === true)} />
            <span className="text-foreground">
              {acknowledgeLiability(
                state.direction,
                totalMoney ?? `${formatPoints(total)} ${POINTS_UNIT()}`,
                effectiveCount,
              )}
            </span>
          </label>
        )}
      </div>
    </div>
  );
}

function liabilityLine(
  projection: ReturnType<typeof bulkLiabilityProjection>,
  direction: AdjustFormState['direction'],
  pointsPerCurrencyUnit: number,
) {
  if (projection.kind === 'none') return null;

  if (projection.kind === 'range') {
    const fromMoney = formatPointsAsMoney(projection.from, pointsPerCurrencyUnit);
    const toMoney = formatPointsAsMoney(projection.to, pointsPerCurrencyUnit);
    return fromMoney && toMoney ? (
      <p className="text-muted-foreground text-xs">{bulkLiabilityRange(fromMoney, toMoney)}</p>
    ) : null;
  }

  const deltaMoney = formatPointsAsMoney(projection.delta, pointsPerCurrencyUnit);
  if (!deltaMoney) return null;

  return (
    <p className="text-muted-foreground text-xs">
      {direction === 'remove' ? bulkLiabilityReducesUpTo(deltaMoney) : addsToLiability(deltaMoney)}
    </p>
  );
}
