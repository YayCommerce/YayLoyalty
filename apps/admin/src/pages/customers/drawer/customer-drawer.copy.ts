import { __, sprintf } from '@wordpress/i18n';

/**
 * The customer drawer's core copy — header, stats, actions, tabs, ledger
 * filters/columns/pager, and the four per-tab empty states. Activity-log and
 * edge-panel copy live in `customer-drawer.activity.copy.ts` and
 * `customer-drawer.panels.copy.ts`. Pure: no React, no fetch.
 *
 * Flat exported functions, not one nested `DRAWER_COPY` object — matches
 * `dashboard-page.copy.ts`'s own style. Every entry is a function, even
 * non-interpolated ones: `__()` must run after the locale is loaded, so
 * nothing here may be a module-scope constant evaluated at import time.
 */

// ─── Header ──────────────────────────────────────────

export function memberSince(date: string): string {
  return sprintf(__('member since %s', 'yayloyalty'), date);
}

export const ACCOUNT_DELETED_LABEL = () => __('ACCOUNT DELETED', 'yayloyalty');

/** Tier card, fed by the sample-data stand-in (see
 *  `customers-page.tier-sample-data.ts`); "X points to <next tier>". */
export function tierProgress(points: string, nextTier: string): string {
  return sprintf(__('%1$s points to %2$s', 'yayloyalty'), points, nextTier);
}
export const TOP_TIER_REACHED_LABEL = () => __('Top tier reached', 'yayloyalty');

// ─── Stats (1e/1f/3c) ────────────────────────────────

export const STAT_BALANCE = () => __('Balance', 'yayloyalty');
export const STAT_PENDING = () => __('Pending', 'yayloyalty');
export const STAT_EXPIRES = () => __('Expires', 'yayloyalty');
export const STAT_LIFETIME_EARNED = () => __('Lifetime earned', 'yayloyalty');
export const STAT_LIFETIME_REDEEMED = () => __('Lifetime redeemed', 'yayloyalty');

/** 1e note 1 — Pending sits beside Balance but is never added to it. */
export const PENDING_SUBLINE = () => __('not added to Balance', 'yayloyalty');
/** 4a — the balance figure on a deleted account. */
export const BALANCE_AT_DELETION_LABEL = () => __('Balance at deletion', 'yayloyalty');
export const BALANCE_AT_DELETION_SUBLINE = () => __('no longer spendable', 'yayloyalty');
/** 4a — no deadline once expiry has stopped. */
export const EXPIRY_STOPPED_SUBLINE = () => __('expiry stopped', 'yayloyalty');

// ─── Actions ─────────────────────────────────────────

export const ACTION_ADJUST_POINTS = () => __('Adjust points', 'yayloyalty');
export const ACTION_RESET_EXPIRY = () => __('Reset expiry', 'yayloyalty');
export const ACTION_EXPORT_LEDGER = () => __('Export ledger', 'yayloyalty');
export const ACTION_ASSIGN_TIER = () => __('Assign tier', 'yayloyalty');

export const NOT_AVAILABLE_YET_REASON = () => __('Not available yet — coming in a future release.', 'yayloyalty');
/** 3a note 2 — nothing to extend on a zero balance. */
export const RESET_EXPIRY_NEEDS_POINTS_REASON = () => __('Needs points to extend.', 'yayloyalty');
/** 4a — the account has no owner left to act on. */
export const DELETED_ACCOUNT_ACTION_REASON = () =>
  __('This account was deleted — there is nobody to spend the points.', 'yayloyalty');

// ─── Tabs ────────────────────────────────────────────

export const TAB_LEDGER = () => __('Points ledger', 'yayloyalty');
export const TAB_ACTIVITY = () => __('Activity log', 'yayloyalty');
export const TAB_REDEMPTIONS = () => __('Redemptions', 'yayloyalty');
export const TAB_REFERRALS = () => __('Referrals', 'yayloyalty');

// ─── Ledger: columns, filters, pager ─────────────────

export const COLUMN_DATE = () => __('Date', 'yayloyalty');
export const COLUMN_DESCRIPTION = () => __('Description', 'yayloyalty');
export const COLUMN_POINTS = () => __('Points', 'yayloyalty');
export const COLUMN_BALANCE = () => __('Balance', 'yayloyalty');

export const FILTER_TYPE_LABEL = () => __('Type', 'yayloyalty');
export const FILTER_TYPE_ALL = () => __('All', 'yayloyalty');
export const FILTER_TYPE_EARNED = () => __('Earned', 'yayloyalty');
export const FILTER_TYPE_REDEEMED = () => __('Redeemed', 'yayloyalty');
export const FILTER_TYPE_ADJUSTED = () => __('Adjusted', 'yayloyalty');
export const FILTER_TYPE_EXPIRED = () => __('Expired', 'yayloyalty');

export const SEARCH_PLACEHOLDER = () => __('Search description or order number', 'yayloyalty');
export const DATE_RANGE_LABEL = () => __('Date range', 'yayloyalty');

/** "1–10 of 4,812 entries · newest first" */
export function pagerSummary(from: number, to: number, total: number): string {
  return sprintf(
    __('%1$s–%2$s of %3$s entries · newest first', 'yayloyalty'),
    from.toLocaleString(),
    to.toLocaleString(),
    total.toLocaleString(),
  );
}

// ─── Per-tab empty states (3a note 3 — each names what creates a row) ──

export const EMPTY_LEDGER_TITLE = () => __('No points activity yet', 'yayloyalty');
export function emptyLedgerBody(firstName: string): string {
  return sprintf(
    __(
      'A row appears here the first time %s earns from an order, a signup or a review, or when an admin adjusts their balance.',
      'yayloyalty',
    ),
    firstName,
  );
}

export const EMPTY_ACTIVITY_TITLE = () => __('Nothing recorded yet', 'yayloyalty');
export const EMPTY_ACTIVITY_BODY = () => __('Tier changes, expiry resets and admin actions appear here.', 'yayloyalty');

// The ledger and activity tabs both show these when a filter hides every row.
export const EMPTY_FILTERED_TITLE = () => __('Nothing matches these filters', 'yayloyalty');
export const EMPTY_FILTERED_BODY = () =>
  __('Try another type, a different search or a wider date range.', 'yayloyalty');

export const EMPTY_REDEMPTIONS_TITLE = () => __('No rewards redeemed yet', 'yayloyalty');
export function emptyRedemptionsBody(firstName: string): string {
  return sprintf(__('A row appears here the first time %s redeems a reward.', 'yayloyalty'), firstName);
}

// ─── Redemptions (3e) ─────────────────────────────────
export const COLUMN_REWARD = () => __('Reward', 'yayloyalty');
export const COLUMN_VALUE = () => __('Value', 'yayloyalty');
export const COLUMN_ORDER = () => __('Order', 'yayloyalty');
export const COLUMN_STATUS = () => __('Status', 'yayloyalty');

export const REDEMPTION_STATUS_APPLIED = () => __('Applied', 'yayloyalty');
export const REDEMPTION_STATUS_RETURNED = () => __('Points returned', 'yayloyalty'); // a refund, positive figure
export const REDEMPTION_STATUS_ISSUED = () => __('Issued', 'yayloyalty');
