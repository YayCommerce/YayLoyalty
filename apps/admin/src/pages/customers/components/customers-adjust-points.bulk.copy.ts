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

/** "Add points to 3 customers" / "Remove points from 3 customers" */
export function bulkSummaryTitle(direction: 'add' | 'remove', count: number): string {
  return direction === 'remove'
    ? sprintf(
        /* translators: %d: number of customers. */
        _n('Remove points from %d customer', 'Remove points from %d customers', count, 'yayloyalty'),
        count,
      )
    : sprintf(
        /* translators: %d: number of customers. */
        _n('Add points to %d customer', 'Add points to %d customers', count, 'yayloyalty'),
        count,
      );
}

/** "2 points per customer" */
export function perCustomerLine(amount: string): string {
  return sprintf(
    /* translators: %s: points per customer, formatted. */
    __('%s points per customer', 'yayloyalty'),
    amount,
  );
}

/** "+6 points total" / "-6 points total" */
export function pointsTotalBadge(signedTotal: string): string {
  return sprintf(
    /* translators: %s: the signed total in points, e.g. "+6". */
    __('%s points total', 'yayloyalty'),
    signedTotal,
  );
}

export const OUTSTANDING_POINTS_LABEL = () => __('Outstanding points of these customers', 'yayloyalty');

/** "To reverse this, remove 2 points from each customer." */
export function reverseHint(direction: 'add' | 'remove', amount: string): string {
  return direction === 'remove'
    ? sprintf(
        /* translators: %s: points per customer, formatted. */
        __('To reverse this, add %s points to each customer.', 'yayloyalty'),
        amount,
      )
    : sprintf(
        /* translators: %s: points per customer, formatted. */
        __('To reverse this, remove %s points from each customer.', 'yayloyalty'),
        amount,
      );
}

/** Add is an upper bound while a selected balance is below zero: points count only once it passes 0. */
export function bulkLiabilityAddsUpTo(points: string): string {
  return sprintf(
    /* translators: %s: number of points, formatted. */
    __('Adds up to %s points to your outstanding liability.', 'yayloyalty'),
    points,
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
    __(
      'This selection matches too many customers to process in the browser (over %d). Narrow the filters and try again.',
      'yayloyalty',
    ),
    limit,
  );
}

/** Shown when the matching set changed since the acknowledgement. */
export function bulkSelectionChanged(latestCount: number, acknowledgedCount: number): string {
  return sprintf(
    /* translators: 1: number of customers matching now; 2: the number the admin acknowledged. */
    __(
      'The selection changed while you were filling this in: %1$d customers now match, not %2$d. Review and confirm again.',
      'yayloyalty',
    ),
    latestCount,
    acknowledgedCount,
  );
}
