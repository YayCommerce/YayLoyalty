import { __, sprintf } from '@wordpress/i18n';

/**
 * Every sentence in the customer drawer — header, stats, actions, tabs,
 * ledger filters/columns/pager, and the four per-tab empty states. Pure: no
 * React, no fetch.
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

/** Tier card — added post-Wave-A over the sample-data stand-in (see
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
/** Shown under the filter row when `type_counts` is null (real path, Wave A). */
export const FILTER_UNAVAILABLE_REASON = () => __('Filtering arrives with the real ledger query.', 'yayloyalty');

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

// ─── Activity log (3d) ────────────────────────────────

export const COLUMN_EVENT = () => __('Event', 'yayloyalty');
export const COLUMN_BY = () => __('By', 'yayloyalty');

export const ACTIVITY_GROUP_ALL = () => __('All', 'yayloyalty');
export const ACTIVITY_GROUP_ADMIN = () => __('Admin actions', 'yayloyalty');
export const ACTIVITY_GROUP_TIER = () => __('Tier changes', 'yayloyalty');
export const ACTIVITY_GROUP_EXPIRY = () => __('Expiry', 'yayloyalty');
export const ACTIVITY_GROUP_EMAILS = () => __('Emails', 'yayloyalty');

export const STAFF_ONLY_LABEL = () => __('STAFF ONLY', 'yayloyalty');
export const AUTOMATIC_ACTOR_LABEL = () => __('Automatic', 'yayloyalty');
export const CUSTOMER_ACTOR_LABEL = () => __('Customer', 'yayloyalty');

/** One static title per `event_type` — 3d draws these as plain labels, no interpolation. */
export const ACTIVITY_TITLE_ACCOUNT_CREATED = () => __('Account created', 'yayloyalty');
export const ACTIVITY_TITLE_POINTS_ADJUSTED = () => __('Points adjusted', 'yayloyalty');
export const ACTIVITY_TITLE_TIER_CHANGED = () => __('Tier changed', 'yayloyalty');
export const ACTIVITY_TITLE_EXPIRY_RESET = () => __('Expiry reset', 'yayloyalty');
export const ACTIVITY_TITLE_REWARD_REDEEMED = () => __('Reward redeemed', 'yayloyalty');
export const ACTIVITY_TITLE_BALANCE_EMAIL_SENT = () => __('Points balance email sent', 'yayloyalty');
export const ACTIVITY_TITLE_EXPIRING_SOON_EMAIL_SENT = () => __('Expiring soon email sent', 'yayloyalty');

/** Every sub-line composes from `event_type` + `data` — see `customer-drawer.activity-rows.ts`. */
export function activityAccountCreatedSubLine(campaign: string, points: string): string {
  return sprintf(__('%1$s · %2$s points', 'yayloyalty'), campaign, points);
}
export function activityPointsAdjustedSubLine(points: string, note: string): string {
  return sprintf(__('%1$s · "%2$s"', 'yayloyalty'), points, note);
}
/** "Explorer → Summit · automatic · reached 5,000 lifetime points" */
export function activityTierChangedSubLine(
  fromTier: string,
  toTier: string,
  automatic: boolean,
  threshold: string,
): string {
  const mode = automatic ? __('automatic', 'yayloyalty') : __('manual', 'yayloyalty');
  return sprintf(
    __('%1$s → %2$s · %3$s · reached %4$s lifetime points', 'yayloyalty'),
    fromTier,
    toTier,
    mode,
    threshold,
  );
}
export function activityExpiryResetSubLine(from: string, to: string, note: string): string {
  return sprintf(__('%1$s → %2$s · "%3$s"', 'yayloyalty'), from, to, note);
}
export function activityRewardRedeemedSubLine(reward: string, orderId: string): string {
  return sprintf(__('%1$s · order #%2$s', 'yayloyalty'), reward, orderId);
}
export function activityBalanceEmailSubLine(subject: string): string {
  return sprintf(__('"%s"', 'yayloyalty'), subject);
}
/** `deadline` is derived client-side from `occurred_at` + `window_days` — not a fixture field. */
export function activityExpiringSoonEmailSubLine(windowDays: string, deadline: string): string {
  return sprintf(__('%1$s-day reminder · deadline was %2$s', 'yayloyalty'), windowDays, deadline);
}

/** Search placeholder for the activity tab's own filter row — distinct from
 *  the ledger's `SEARCH_PLACEHOLDER` (no "order number" here; matches the
 *  event text and who did it instead — see `DrawerActivity::matches_search()`). */
export const ACTIVITY_SEARCH_PLACEHOLDER = () => __('Search event or who did it', 'yayloyalty');

/** 3d's footer note — load-bearing: stops an admin hunting for an adjustment here. */
export const ACTIVITY_FOOTER_NOTE = () =>
  __(
    'Points movements are not repeated here — they live in the points ledger with their running balance.',
    'yayloyalty',
  );
