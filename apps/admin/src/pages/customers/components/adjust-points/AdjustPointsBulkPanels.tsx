import { formatPoints } from '@/lib/helpers/points.helper';
import { Checkbox } from '@/components/ui/checkbox';
import { Skeleton } from '@/components/ui/skeleton';
import { BALANCE_RANGE_OPTIONS, EXPIRING_SOON_OPTIONS, type CustomerListFilters } from '../../customers-page.filters';
import { MAX_MATCHING_ROWS } from '../../customers-page.matching-customers';
import {
  acknowledgeLiability,
  bulkFilterLine,
  bulkLiabilityReducesUpTo,
  bulkNotShownOnPage,
  bulkSummaryTitle,
  bulkTooLargeSelection,
  OUTSTANDING_POINTS_LABEL,
  perCustomerLine,
  pointsTotalBadge,
  reverseHint,
} from '../customers-adjust-points.bulk.copy';
import { addsToLiability } from '../customers-adjust-points.copy';
import {
  bulkLiabilityProjection,
  bulkTotalPoints,
  signedPoints,
  type AdjustFormState,
} from '../customers-adjust-points.form-state';
import { AdjustPointsBeforeAfter } from './AdjustPointsBeforeAfter';
import { AdjustPointsSummaryCard } from './AdjustPointsSummaryCard';

/** The active filters as text. */
function filterSummary(filters: CustomerListFilters, tierLabel: string | null): string | null {
  const parts: string[] = [];

  if (filters.search.trim()) parts.push(`"${filters.search.trim()}"`);

  const balanceLabel = BALANCE_RANGE_OPTIONS.find((option) => option.value === filters.balanceRange)?.label;
  if (filters.balanceRange !== 'any' && balanceLabel) parts.push(balanceLabel);

  const expiringLabel = EXPIRING_SOON_OPTIONS.find((option) => option.value === filters.expiringSoon)?.label;
  if (filters.expiringSoon !== 'off' && expiringLabel) parts.push(expiringLabel);

  if (filters.tier !== '' && tierLabel) parts.push(tierLabel);

  return parts.length > 0 ? parts.join(' · ') : null;
}

/** Bulk-only summary card: who, how much, the liability before → after, and the acknowledgement. */
export function AdjustPointsBulkPanels({
  state,
  effectiveCount,
  isCountLoading,
  selectionMode,
  filters,
  tierLabel,
  notShownOnPage,
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
  filters: CustomerListFilters;
  /** The tier filter's choices as text; null without one. */
  tierLabel: string | null;
  /** Rows the filter matches beyond the current page; 0 when none. */
  notShownOnPage: number;
  liabilityPoints: number | null;
  /** The preview errored: `liabilityPoints` can't be trusted, so the liability figures are hidden rather than guessed at. */
  hasPreviewError: boolean;
  isOverLimit: boolean;
  acknowledged: boolean;
  onAcknowledgedChange: (checked: boolean) => void;
}) {
  const summary = selectionMode === 'all' ? filterSummary(filters, tierLabel) : null;
  const total = bulkTotalPoints(state, effectiveCount);
  const projection = bulkLiabilityProjection(state.direction, total, liabilityPoints);
  const amount = formatPoints(state.amount);
  const hasAmount = state.amount > 0 && !isOverLimit;
  const signedTotal = signedPoints({ direction: state.direction, amount: total });

  return (
    <AdjustPointsSummaryCard
      title={isCountLoading ? <Skeleton className="h-5 w-48" /> : bulkSummaryTitle(state.direction, effectiveCount)}
      subtitle={perCustomerLine(amount)}
      badge={hasAmount ? pointsTotalBadge(`${signedTotal > 0 ? '+' : ''}${formatPoints(signedTotal)}`) : undefined}
    >
      {(summary || (selectionMode === 'all' && notShownOnPage > 0) || isOverLimit) && (
        <div className="flex flex-col gap-1 text-xs">
          {summary && <p className="text-muted-foreground m-0">{bulkFilterLine(summary)}</p>}
          {selectionMode === 'all' && notShownOnPage > 0 && (
            <p className="text-muted-foreground m-0">{bulkNotShownOnPage(notShownOnPage)}</p>
          )}
          {isOverLimit && <p className="text-destructive m-0">{bulkTooLargeSelection(MAX_MATCHING_ROWS)}</p>}
        </div>
      )}

      {hasAmount && !hasPreviewError && projection.kind === 'range' && (
        <AdjustPointsBeforeAfter caption={OUTSTANDING_POINTS_LABEL()} before={projection.from} after={projection.to} />
      )}
      {hasAmount && !hasPreviewError && projection.kind === 'delta' && (
        <p className="text-muted-foreground m-0 text-xs">
          {state.direction === 'remove'
            ? bulkLiabilityReducesUpTo(formatPoints(projection.delta))
            : addsToLiability(formatPoints(projection.delta))}
        </p>
      )}

      {hasAmount && (
        <>
          <p className="text-muted-foreground m-0 text-xs">{reverseHint(state.direction, amount)}</p>
          <label className="flex items-start gap-2.5 border-t pt-3 text-sm">
            <Checkbox checked={acknowledged} onCheckedChange={(checked) => onAcknowledgedChange(checked === true)} />
            <span>{acknowledgeLiability(state.direction, formatPoints(total), effectiveCount)}</span>
          </label>
        </>
      )}
    </AdjustPointsSummaryCard>
  );
}
