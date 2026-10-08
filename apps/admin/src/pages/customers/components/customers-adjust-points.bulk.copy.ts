import { __, _n, sprintf } from '@wordpress/i18n';

/** The bulk Adjust-points strings. Functions, not constants: `__()` must run after the locale loads. */

export function bulkHeaderTitle(count: number): string {
  return sprintf(
    /* translators: %d: number of customers. */
    __('Adjust points for %d customers', 'yayloyalty'),
    count,
  );
}
export const BULK_HEADER_SUBTITLE = () => __('Bulk action · one adjustment written per customer', 'yayloyalty');

export function bulkSelectionCount(count: number): string {
  return sprintf(
    /* translators: %d: number of customers selected. */
    _n('%d customer selected', '%d customers selected', count, 'yayloyalty'),
    count,
  );
}
export function bulkFilterLine(summary: string): string {
  return sprintf(
    /* translators: %s: the active filters, joined with " · ". */
    __('Filter: %s', 'yayloyalty'),
    summary,
  );
}
export function bulkNotShownOnPage(count: number): string {
  return sprintf(
    /* translators: %d: number of matching customers not on the current page. */
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
  return sprintf(
    /* translators: 1: number of customers; 2: points per customer, formatted. */
    __('%1$d customers × %2$s points', 'yayloyalty'),
    count,
    amount,
  );
}
export function bulkTotalPointsLine(points: string): string {
  return sprintf(
    /* translators: %s: total points, formatted. */
    __('%s points', 'yayloyalty'),
    points,
  );
}
export function bulkLiabilityRange(fromPoints: string, toPoints: string): string {
  return sprintf(
    /* translators: 1: liability before the adjustment, in points; 2: liability after it, in points. */
    __('Raises your outstanding liability from %1$s to %2$s points.', 'yayloyalty'),
    fromPoints,
    toPoints,
  );
}
/** Remove is always an upper bound: a balance that crosses zero stops counting as liability. */
export function bulkLiabilityReducesUpTo(points: string): string {
  return sprintf(
    /* translators: %s: number of points, formatted. */
    __('Reduces your outstanding liability by up to %s points.', 'yayloyalty'),
    points,
  );
}

export function bulkNotifyByEmailCount(count: number): string {
  return sprintf(
    /* translators: %d: number of emails. */
    _n('%d email', '%d emails', count, 'yayloyalty'),
    count,
  );
}

export const NO_UNDO_TITLE = () => __("This can't be undone in one step", 'yayloyalty');
export function noUndoBody(count: number): string {
  return sprintf(
    /* translators: %d: number of customers. */
    __(
      '%d separate adjustments are written, one per customer. Reversing them means running a second bulk adjustment in the opposite direction, which leaves both sets of rows in every customer’s history.',
      'yayloyalty',
    ),
    count,
  );
}
export function writtenToLedgers(count: number): string {
  return sprintf(
    /* translators: %d: number of customers. */
    __('Written to all %d ledgers against your account. Only staff can see it.', 'yayloyalty'),
    count,
  );
}
export function acknowledgeLiability(direction: 'add' | 'remove', points: string, count: number): string {
  return direction === 'remove'
    ? sprintf(
        /* translators: 1: total points, formatted; 2: number of customers. */
        __('I understand this removes %1$s points of liability across %2$d customers', 'yayloyalty'),
        points,
        count,
      )
    : sprintf(
        /* translators: 1: total points, formatted; 2: number of customers. */
        __('I understand this adds %1$s points of liability across %2$d customers', 'yayloyalty'),
        points,
        count,
      );
}

export function bulkSubmitLabel(direction: 'add' | 'remove', amount: string, count: number): string {
  return direction === 'remove'
    ? sprintf(
        /* translators: 1: points per customer, formatted; 2: number of customers. */
        __('Remove %1$s points from %2$d customers', 'yayloyalty'),
        amount,
        count,
      )
    : sprintf(
        /* translators: 1: points per customer, formatted; 2: number of customers. */
        __('Add %1$s points to %2$d customers', 'yayloyalty'),
        amount,
        count,
      );
}

/** `limit` is `MAX_MATCHING_ROWS`; shown by the dialog up front and thrown by the bulk walk. */
export function bulkTooLargeSelection(limit: number): string {
  return sprintf(
    /* translators: %d: the most customers one bulk action can process. */
    __('This selection matches too many customers to process in the browser (over %d). Narrow the filters and try again.', 'yayloyalty'),
    limit,
  );
}

/** Shown when the matching set changed since the acknowledgement. */
export function bulkSelectionChanged(latestCount: number, acknowledgedCount: number): string {
  return sprintf(
    /* translators: 1: number of customers matching now; 2: the number the admin acknowledged. */
    __('The selection changed while you were filling this in: %1$d customers now match, not %2$d. Review and confirm again.', 'yayloyalty'),
    latestCount,
    acknowledgedCount,
  );
}
