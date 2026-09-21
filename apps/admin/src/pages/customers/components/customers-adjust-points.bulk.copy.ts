import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Bulk-only strings (artboard 2g). Split from `customers-adjust-points.copy.ts`
 * once that file neared the 200-line cap — same reasoning
 * `customer-drawer.panels.copy.ts` gives for its own split. Same rules: flat
 * exported functions, every entry a function, pure.
 */

export function bulkHeaderTitle(count: number): string {
  return sprintf(__('Adjust points for %d customers', 'yayloyalty'), count);
}
export const BULK_HEADER_SUBTITLE = () => __('Bulk action · one adjustment written per customer', 'yayloyalty');

export function bulkSelectionCount(count: number): string {
  return sprintf(_n('%d customer selected', '%d customers selected', count, 'yayloyalty'), count);
}
export function bulkFilterLine(summary: string): string {
  return sprintf(__('Filter: %s', 'yayloyalty'), summary);
}
export function bulkNotShownOnPage(count: number): string {
  return sprintf(
    _n(
      'Matches every customer in the filter, including %d not shown on this page.',
      'Matches every customer in the filter, including the %d not shown on this page.',
      count,
      'yayloyalty',
    ),
    count,
  );
}

export const AMOUNT_EACH_LABEL = () => __('Amount, each', 'yayloyalty');

/** "340 customers × 500 points" */
export function bulkTotalsLine(count: number, amount: string): string {
  return sprintf(__('%1$d customers × %2$s points', 'yayloyalty'), count, amount);
}
export function bulkTotalPointsLine(points: string, money: string | null): string {
  return money ? sprintf(__('%1$s points (%2$s)', 'yayloyalty'), points, money) : sprintf(__('%s points', 'yayloyalty'), points);
}
export function bulkLiabilityRange(fromMoney: string, toMoney: string): string {
  return sprintf(__('Raises your outstanding liability from %1$s to %2$s.', 'yayloyalty'), fromMoney, toMoney);
}
/** Remove is always an upper bound: balances that cross zero stop contributing — see `liabilityDelta()`. */
export function bulkLiabilityReducesUpTo(money: string): string {
  return sprintf(__('Reduces your outstanding liability by up to %s.', 'yayloyalty'), money);
}

export function bulkNotifyByEmailCount(count: number): string {
  return sprintf(_n('%d email', '%d emails', count, 'yayloyalty'), count);
}

export const NO_UNDO_TITLE = () => __("This can't be undone in one step", 'yayloyalty');
export function noUndoBody(count: number): string {
  return sprintf(
    __(
      '%d separate adjustments are written, one per customer. Reversing them means running a second bulk adjustment in the opposite direction, which leaves both sets of rows in every customer’s history.',
      'yayloyalty',
    ),
    count,
  );
}
export function writtenToLedgers(count: number): string {
  return sprintf(__('Written to all %d ledgers against your account. Only staff can see it.', 'yayloyalty'), count);
}
export function acknowledgeLiability(direction: 'add' | 'remove', money: string, count: number): string {
  return direction === 'remove'
    ? sprintf(__('I understand this removes %1$s of liability across %2$d customers', 'yayloyalty'), money, count)
    : sprintf(__('I understand this adds %1$s of liability across %2$d customers', 'yayloyalty'), money, count);
}

export function bulkSubmitLabel(direction: 'add' | 'remove', amount: string, count: number): string {
  return direction === 'remove'
    ? sprintf(__('Remove %1$s points from %2$d customers', 'yayloyalty'), amount, count)
    : sprintf(__('Add %1$s points to %2$d customers', 'yayloyalty'), amount, count);
}

/** The existing wording from `customers-page.matching-customers.ts`'s own hard bound. */
export function bulkTooLargeSelection(limit: number): string {
  return sprintf(
    __('This selection matches too many customers to process in the browser (over %d). Narrow the filters and try again.', 'yayloyalty'),
    limit,
  );
}

/** Surfaced when the matching set changed since the acknowledgement (see `customers-page.bulk-actions.ts`). */
export function bulkSelectionChanged(latestCount: number, acknowledgedCount: number): string {
  return sprintf(
    __('The selection changed while you were filling this in: %1$d customers now match, not %2$d. Review and confirm again.', 'yayloyalty'),
    latestCount,
    acknowledgedCount,
  );
}
