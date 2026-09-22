import { __, sprintf } from '@wordpress/i18n';

/**
 * Activity-log titles and sub-line composers — moved out of
 * `customer-drawer.copy.ts` once that file hit the 200-line cap (F09 Wave B
 * account-delete task). Same rules as the main copy module: flat exported
 * functions, every entry a function (never a module-scope constant — `__()`
 * must run after the locale loads), pure — no React, no fetch.
 */

/** One static title per `event_type` — 3d draws these as plain labels, no interpolation. */
export const ACTIVITY_TITLE_ACCOUNT_CREATED = () => __('Account created', 'yayloyalty');
export const ACTIVITY_TITLE_POINTS_ADJUSTED = () => __('Points adjusted', 'yayloyalty');
export const ACTIVITY_TITLE_TIER_CHANGED = () => __('Tier changed', 'yayloyalty');
export const ACTIVITY_TITLE_EXPIRY_RESET = () => __('Expiry reset', 'yayloyalty');
export const ACTIVITY_TITLE_REWARD_REDEEMED = () => __('Reward redeemed', 'yayloyalty');
export const ACTIVITY_TITLE_BALANCE_EMAIL_SENT = () => __('Points balance email sent', 'yayloyalty');
export const ACTIVITY_TITLE_EXPIRING_SOON_EMAIL_SENT = () => __('Expiring soon email sent', 'yayloyalty');
export const ACTIVITY_TITLE_ACCOUNT_DELETED = () => __('Account deleted', 'yayloyalty');

/** Every sub-line composes from `event_type` + `data` — see `customer-drawer.activity-rows.ts`. */
export function activityAccountCreatedSubLine(points: string): string {
  return sprintf(__('%s points', 'yayloyalty'), points);
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
/** `deadline` is derived client-side from `occurred_at` + `window_days`. */
export function activityExpiringSoonEmailSubLine(windowDays: string, deadline: string): string {
  return sprintf(__('%1$s-day reminder · deadline was %2$s', 'yayloyalty'), windowDays, deadline);
}
/** "member@example.com · 1,200 points frozen" */
export function activityAccountDeletedSubLine(email: string, balanceAtDeletion: string): string {
  return sprintf(__('%1$s · %2$s points frozen', 'yayloyalty'), email, balanceAtDeletion);
}
