import { __, _n, sprintf } from '@wordpress/i18n';

import { formatStoreNumber } from '@/lib/helpers/format.helper';

/** The customer drawer's core strings. Functions, not constants: `__()` must run after the locale loads. */

// Header

export function memberSince(date: string): string {
  return sprintf(
    /* translators: %s: the date the customer joined, formatted. */
    __('member since %s', 'yayloyalty'),
    date,
  );
}

export const ACCOUNT_DELETED_LABEL = () => __('ACCOUNT DELETED', 'yayloyalty');

// Stats

export const STATS_TITLE = () => __('Loyalty overview', 'yayloyalty');
export const STAT_BALANCE = () => __('Points balance', 'yayloyalty');
export const STAT_PENDING = () => __('Pending points', 'yayloyalty');
export const STAT_NEXT_EXPIRY = () => __('Next expiry', 'yayloyalty');
export const STAT_LIFETIME_EARNED = () => __('Lifetime earned', 'yayloyalty');
export const STAT_LIFETIME_REDEEMED = () => __('Lifetime redeemed', 'yayloyalty');

/** Pending sits beside Balance but is never added to it. */
export const PENDING_SUBLINE = () => __('not added to Balance', 'yayloyalty');
/** The balance figure on a deleted account. */
export const BALANCE_AT_DELETION_LABEL = () => __('Balance at deletion', 'yayloyalty');
export const BALANCE_AT_DELETION_SUBLINE = () => __('no longer spendable', 'yayloyalty');
/** No deadline once expiry has stopped. */
export const EXPIRY_STOPPED_SUBLINE = () => __('expiry stopped', 'yayloyalty');

// History

export const HISTORY_TITLE = () => __('Customer history', 'yayloyalty');

// Actions

export const ACTION_ADJUST_POINTS = () => __('Adjust points', 'yayloyalty');
export const ACTION_EXPORT_LEDGER = () => __('Export ledger', 'yayloyalty');

export const EXPORT_LEDGER_EMPTY = () => __('No ledger entries to export.', 'yayloyalty');
export const EXPORT_LEDGER_ERROR_FALLBACK = () => __('Could not export the ledger.', 'yayloyalty');
export function exportLedgerTooLarge(max: number): string {
  return sprintf(
    /* translators: %d: the most ledger entries one export can hold. */
    __('This history has more than %d entries — too many to export.', 'yayloyalty'),
    max,
  );
}
/** The ledger CSV's header row, in column order. */
export const LEDGER_CSV_HEADERS = () => [
  __('Entry ID', 'yayloyalty'),
  __('Date', 'yayloyalty'),
  __('Type', 'yayloyalty'),
  __('Description', 'yayloyalty'),
  __('Points', 'yayloyalty'),
  __('Balance after', 'yayloyalty'),
  __('Order ID', 'yayloyalty'),
  __('Campaign', 'yayloyalty'),
  __('Note', 'yayloyalty'),
  __('Customer description', 'yayloyalty'),
];

export const NOT_AVAILABLE_YET_REASON = () => __('Not available yet — coming in a future release.', 'yayloyalty');
/** The account has no owner left to act on. */
export const DELETED_ACCOUNT_ACTION_REASON = () =>
  __('This account was deleted — there is nobody to spend the points.', 'yayloyalty');

// Tabs

export const TAB_LEDGER = () => __('Points ledger', 'yayloyalty');
export const TAB_ACTIVITY = () => __('Activity log', 'yayloyalty');
export const TAB_REDEMPTIONS = () => __('Redemptions', 'yayloyalty');
export const TAB_REFERRALS = () => __('Referrals', 'yayloyalty');

// Ledger columns, filters and pager

export const COLUMN_DATE = () => __('Date', 'yayloyalty');
export const COLUMN_POINTS = () => __('Points', 'yayloyalty');

export const FILTER_TYPE_LABEL = () => __('Type', 'yayloyalty');
export const FILTER_TYPE_ALL = () => __('All', 'yayloyalty');

export const SEARCH_PLACEHOLDER = () => __('Search description or order number', 'yayloyalty');
export const DATE_RANGE_LABEL = () => __('Date range', 'yayloyalty');
export const DATE_RANGE_RESET = () => __('Reset', 'yayloyalty');

/** "1–10 of 4,812 entries · newest first" */
export function pagerSummary(from: number, to: number, total: number): string {
  return sprintf(
    /* translators: 1: number of the first entry shown; 2: number of the last entry shown; 3: total entries. */
    __('%1$s–%2$s of %3$s entries · newest first', 'yayloyalty'),
    formatStoreNumber(from, 0),
    formatStoreNumber(to, 0),
    formatStoreNumber(total, 0),
  );
}

// Per-tab empty states. Each names what creates a row.

export const EMPTY_LEDGER_TITLE = () => __('No points activity yet', 'yayloyalty');
export function emptyLedgerBody(firstName: string): string {
  return sprintf(
    /* translators: %s: the customer's first name. */
    __(
      'A row appears here the first time %s earns from an order, a signup or a review, or when an admin adjusts their balance.',
      'yayloyalty',
    ),
    firstName,
  );
}

export const EMPTY_ACTIVITY_TITLE = () => __('Nothing recorded yet', 'yayloyalty');
export const EMPTY_ACTIVITY_BODY = () => __('Expiry resets and admin actions appear here.', 'yayloyalty');

// The ledger and activity tabs both show these when a filter hides every row.
export const EMPTY_FILTERED_TITLE = () => __('Nothing matches these filters', 'yayloyalty');
export const EMPTY_FILTERED_BODY = () =>
  __('Try another type, a different search or a wider date range.', 'yayloyalty');

export const EMPTY_REDEMPTIONS_TITLE = () => __('No rewards redeemed yet', 'yayloyalty');
export function emptyRedemptionsBody(firstName: string): string {
  return sprintf(
    /* translators: %s: the customer's first name. */
    __('A row appears here the first time %s redeems a reward.', 'yayloyalty'),
    firstName,
  );
}

// Redemptions
export function balanceAfter(balance: string): string {
  return sprintf(
    /* translators: %s: the customer's points balance after this entry. */
    __('Balance after: %s', 'yayloyalty'),
    balance,
  );
}

/** The ledger's points badge: "+175 points" or "-45 points". */
export function ledgerPointsBadge(points: number): string {
  const amount = formatStoreNumber(Math.abs(points), 0);

  return points < 0
    ? sprintf(
        /* translators: %s: points taken from the customer. */
        _n('-%s point', '-%s points', Math.abs(points), 'yayloyalty'),
        amount,
      )
    : sprintf(
        /* translators: %s: points given to the customer. */
        _n('+%s point', '+%s points', points, 'yayloyalty'),
        amount,
      );
}

/** A redemption's sub-line: its value, and its order when it has one. */
export function redemptionDetails(value: string, order: string): string {
  return order === '—'
    ? sprintf(
        /* translators: %s: the reward's value, formatted as money. */
        __('Worth %s', 'yayloyalty'),
        value,
      )
    : sprintf(
        /* translators: 1: the reward's value, formatted as money; 2: the order number, such as #700. */
        __('Worth %1$s · Order %2$s', 'yayloyalty'),
        value,
        order,
      );
}

export const REDEMPTION_STATUS_APPLIED = () => __('Applied', 'yayloyalty');
export const REDEMPTION_STATUS_REFUNDED = () => __('Points returned', 'yayloyalty'); // status `refunded`
export const REDEMPTION_STATUS_CANCELLED = () => __('Cancelled', 'yayloyalty');
export const REDEMPTION_STATUS_REMOVED = () => __('Removed by admin', 'yayloyalty'); // status `removed`
