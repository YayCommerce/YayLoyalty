import { __, sprintf } from '@wordpress/i18n';

/** The Referrals tab's strings. Functions, not constants: `__()` must run after the locale loads. */

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

/** A pending row states what is missing and when the window closes. */
export function pendingWaitingOnOrder(windowCloses: string): string {
  return sprintf(
    /* translators: %s: the date the referral window closes, formatted. */
    __('waiting on a first order · window closes %s', 'yayloyalty'),
    windowCloses,
  );
}
/** Same shape, once the window has actually lapsed with no order. */
export const REFERRAL_LAPSED_NOTE = () => __('window closed with no qualifying order', 'yayloyalty');

/** The coupon's state, shown beside its label. */
export const COUPON_USED_LABEL = () => __('used', 'yayloyalty');
export const COUPON_UNUSED_LABEL = () => __('unused', 'yayloyalty');
export const COUPON_ISSUED_LABEL = () => __('issued', 'yayloyalty');

export const REFERRAL_LINK_LABEL = () => __('Referral link', 'yayloyalty');
export const REFERRAL_SETTINGS_LINK = () => __('Referral settings', 'yayloyalty');

/** "500 points to the referrer and a $5 coupon to their friend, active for 90 days." Composed from the `campaign` block. */
export function campaignSentence(referrerPoints: string, couponLabel: string, windowDays: number): string {
  return sprintf(
    /* translators: 1: points to the referrer, formatted; 2: the friend's coupon label, such as "$5 coupon"; 3: days the offer stays active. */
    __('%1$s points to the referrer and a %2$s to their friend, active for %3$s days.', 'yayloyalty'),
    referrerPoints,
    couponLabel,
    String(windowDays),
  );
}
