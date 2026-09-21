import { __, sprintf } from '@wordpress/i18n';

/**
 * 3f's prose — the Referrals tab's columns, statuses, pending-row copy and
 * campaign sentence. Split from `customer-drawer.copy.ts` (phase 06):
 * that file is at its 200-line hard cap (see plan.md's "Hard constraints"),
 * the same reason `customer-drawer.panels.copy.ts` split off in phase 02.
 * Pure: no React, no fetch.
 */

export const EMPTY_REFERRALS_TITLE = () => __('No referrals yet', 'yayloyalty');
export const EMPTY_REFERRALS_BODY = () => __('The link works from the day the account exists.', 'yayloyalty');

export const COLUMN_REFERRED = () => __('Referred', 'yayloyalty');
export const COLUMN_SIGNED_UP = () => __('Signed up', 'yayloyalty');
export const COLUMN_FIRST_ORDER = () => __('First order', 'yayloyalty');
export const COLUMN_TO_REFERRER = () => __('To referrer', 'yayloyalty');
export const COLUMN_TO_FRIEND = () => __('To friend', 'yayloyalty');
export const COLUMN_REFERRAL_STATUS = () => __('Status', 'yayloyalty');

export const REFERRAL_STATUS_COMPLETED = () => __('Completed', 'yayloyalty');
export const REFERRAL_STATUS_PENDING = () => __('Pending', 'yayloyalty');
export const REFERRAL_STATUS_LAPSED = () => __('Lapsed', 'yayloyalty');

/** 3f callout 2 — a pending row must state what is missing AND when the window closes. */
export function pendingWaitingOnOrder(windowCloses: string): string {
  return sprintf(__('waiting on a first order · window closes %s', 'yayloyalty'), windowCloses);
}
/** Same shape, once the window has actually lapsed with no order. */
export const REFERRAL_LAPSED_NOTE = () => __('window closed with no qualifying order', 'yayloyalty');

/** The coupon's used/unused state, alongside its label — 3f shows both sides of the reward. */
export const COUPON_USED_LABEL = () => __('used', 'yayloyalty');
export const COUPON_UNUSED_LABEL = () => __('unused', 'yayloyalty');
export const COUPON_ISSUED_LABEL = () => __('issued', 'yayloyalty');

export const REFERRAL_LINK_LABEL = () => __('Referral link', 'yayloyalty');
export const REFERRAL_SETTINGS_LINK = () => __('Referral settings', 'yayloyalty');

/** "500 points to Daniel and a $5 coupon to their friend, active for 90 days." — composed from the `campaign` block, never stored as a sentence. */
export function campaignSentence(referrerPoints: string, couponLabel: string, windowDays: number): string {
  return sprintf(
    __('%1$s points to the referrer and a %2$s to their friend, active for %3$s days.', 'yayloyalty'),
    referrerPoints,
    couponLabel,
    String(windowDays),
  );
}
