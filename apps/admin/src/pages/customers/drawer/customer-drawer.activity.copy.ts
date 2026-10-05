import { __, sprintf } from '@wordpress/i18n';

/** The Activity log tab's strings. Functions, not constants: `__()` must run after the locale loads. */

/** One static title per `event_type`. */
export const ACTIVITY_TITLE_ACCOUNT_CREATED = () => __('Account created', 'yayloyalty');
export const ACTIVITY_TITLE_POINTS_ADJUSTED = () => __('Points adjusted', 'yayloyalty');
export const ACTIVITY_TITLE_TIER_CHANGED = () => __('Tier changed', 'yayloyalty');
export const ACTIVITY_TITLE_EXPIRY_RESET = () => __('Expiry reset', 'yayloyalty');
export const ACTIVITY_TITLE_REWARD_REDEEMED = () => __('Reward redeemed', 'yayloyalty');
export const ACTIVITY_TITLE_BALANCE_EMAIL_SENT = () => __('Points balance email sent', 'yayloyalty');
export const ACTIVITY_TITLE_EXPIRING_SOON_EMAIL_SENT = () => __('Expiring soon email sent', 'yayloyalty');
export const ACTIVITY_TITLE_ACCOUNT_DELETED = () => __('Account deleted', 'yayloyalty');

export function activityAccountCreatedSubLine(points: string): string {
  return sprintf(
    /* translators: %s: number of points, formatted. */
    __('%s points', 'yayloyalty'),
    points,
  );
}
export function activityPointsAdjustedSubLine(points: string, note: string): string {
  return sprintf(
    /* translators: 1: the signed points change, such as +500; 2: the admin's note. */
    __('%1$s · "%2$s"', 'yayloyalty'),
    points,
    note,
  );
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
    /* translators: 1: previous tier; 2: new tier; 3: "automatic" or "manual"; 4: the lifetime points threshold, formatted. */
    __('%1$s → %2$s · %3$s · reached %4$s lifetime points', 'yayloyalty'),
    fromTier,
    toTier,
    mode,
    threshold,
  );
}
export function activityExpiryResetSubLine(from: string, to: string, note: string): string {
  return sprintf(
    /* translators: 1: previous expiry date, formatted; 2: new expiry date, formatted; 3: the admin's note. */
    __('%1$s → %2$s · "%3$s"', 'yayloyalty'),
    from,
    to,
    note,
  );
}
export function activityRewardRedeemedSubLine(reward: string, orderId: string): string {
  return sprintf(
    /* translators: 1: the reward's name; 2: the order number. */
    __('%1$s · order #%2$s', 'yayloyalty'),
    reward,
    orderId,
  );
}
export function activityBalanceEmailSubLine(subject: string): string {
  return sprintf(
    /* translators: %s: the email's subject line. */
    __('"%s"', 'yayloyalty'),
    subject,
  );
}
export function activityExpiringSoonEmailSubLine(windowDays: string, deadline: string): string {
  return sprintf(
    /* translators: 1: the reminder window in days; 2: the deadline date, formatted. */
    __('%1$s-day reminder · deadline was %2$s', 'yayloyalty'),
    windowDays,
    deadline,
  );
}
/** "member@example.com · 1,200 points frozen" */
export function activityAccountDeletedSubLine(email: string, balanceAtDeletion: string): string {
  return sprintf(
    /* translators: 1: the customer's email address; 2: their balance at deletion, formatted. */
    __('%1$s · %2$s points frozen', 'yayloyalty'),
    email,
    balanceAtDeletion,
  );
}

export const COLUMN_EVENT = () => __('Event', 'yayloyalty');
export const COLUMN_BY = () => __('By', 'yayloyalty');

/** Accessible name for the group control — it shows no "Show:" prefix of its own. */
export const ACTIVITY_GROUP_LABEL = () => __('Show', 'yayloyalty');
export const ACTIVITY_GROUP_ALL = () => __('All', 'yayloyalty');
export const ACTIVITY_GROUP_ADMIN = () => __('Admin actions', 'yayloyalty');
export const ACTIVITY_GROUP_TIER = () => __('Tier changes', 'yayloyalty');
export const ACTIVITY_GROUP_EXPIRY = () => __('Expiry', 'yayloyalty');
export const ACTIVITY_GROUP_EMAILS = () => __('Emails', 'yayloyalty');

export const STAFF_ONLY_LABEL = () => __('STAFF ONLY', 'yayloyalty');
export const AUTOMATIC_ACTOR_LABEL = () => __('Automatic', 'yayloyalty');
export const CUSTOMER_ACTOR_LABEL = () => __('Customer', 'yayloyalty');

/** Unlike the ledger's placeholder, no order number: this search matches the event text and who did it. */
export const ACTIVITY_SEARCH_PLACEHOLDER = () => __('Search event or who did it', 'yayloyalty');

/** Stops an admin hunting for an adjustment here. */
export const ACTIVITY_FOOTER_NOTE = () =>
  __(
    'Points movements are not repeated here — they live in the points ledger with their running balance.',
    'yayloyalty',
  );
