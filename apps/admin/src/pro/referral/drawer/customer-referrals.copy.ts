import { __, sprintf } from '@wordpress/i18n';

import { formatCount } from '@/lib/helpers/points.helper';

/** The customer drawer's Referrals tab strings. Functions, not constants: `__()` must run after the locale loads. */
export const CUSTOMER_REFERRALS_COPY = {
  referred: () => __('Referred', 'yayloyalty'),
  /* translators: 1: rewarded referrals, 2: referrals still waiting */
  referredSub: (rewarded: number, waiting: number) =>
    sprintf(__('%1$s rewarded · %2$s pending', 'yayloyalty'), formatCount(rewarded), formatCount(waiting)),
  pointsEarned: () => __('Points earned', 'yayloyalty'),
  /* translators: %s: a percentage, e.g. "7" */
  pointsShare: (percent: number) => sprintf(__('%s%% of their lifetime total', 'yayloyalty'), formatCount(percent)),
  revenue: () => __('Revenue referred', 'yayloyalty'),
  revenueSub: () => __('first orders only', 'yayloyalty'),
  link: () => __('Referral link', 'yayloyalty'),
  copy: () => __('Copy', 'yayloyalty'),
  copied: () => __('Copied', 'yayloyalty'),
  noLink: () => __('This account was deleted, so it has no referral link.', 'yayloyalty'),
  /* translators: 1: points with their label, 2: the customer's first name, 3: the friend's discount, 4: days */
  termsLine: (points: string, name: string, discount: string, days: number) =>
    sprintf(
      __("%1$s to %2$s and %3$s off for the friend, when the friend's first order completes. %4$s-day window.", 'yayloyalty'),
      points,
      name,
      discount,
      formatCount(days),
    ),
  programOff: () => __('The referral program is switched off, so new links attribute no one.', 'yayloyalty'),
  settings: () => __('Referral settings', 'yayloyalty'),
  columnReferred: () => __('Referred', 'yayloyalty'),
  columnSignedUp: () => __('Signed up', 'yayloyalty'),
  columnFirstOrder: () => __('First order', 'yayloyalty'),
  /* translators: %s: the customer's first name */
  columnToAdvocate: (name: string) => sprintf(__('To %s', 'yayloyalty'), name),
  columnToFriend: () => __('To friend', 'yayloyalty'),
  columnStatus: () => __('Status', 'yayloyalty'),
  noQualifyingOrder: () => __('no qualifying order yet', 'yayloyalty'),
  /* translators: %s: points */
  onHoldPoints: (points: string) => sprintf(__('%s on hold', 'yayloyalty'), points),
  /* translators: %s: the friend's discount, e.g. "$10.00" */
  discountUsed: (discount: string) => sprintf(__('%s · used', 'yayloyalty'), discount),
  /* translators: 1: the friend's discount, 2: a date */
  discountAvailable: (discount: string, date: string) =>
    sprintf(__('%1$s · available until %2$s', 'yayloyalty'), discount, date),
  /* translators: %s: the friend's discount */
  discountExpired: (discount: string) => sprintf(__('%s · expired', 'yayloyalty'), discount),
  /* translators: %s: the friend's discount */
  discountUnused: (discount: string) => sprintf(__('%s · not used', 'yayloyalty'), discount),
  /* translators: %s: a date */
  pendingNote: (date: string) =>
    sprintf(__('Waiting on a first order. The window closes %s, after which no points are paid.', 'yayloyalty'), date),
  emptyTitle: () => __('No referrals yet.', 'yayloyalty'),
  emptyBody: () => __('The link works from the day the account exists.', 'yayloyalty'),
  loadError: () => __('Could not load referrals.', 'yayloyalty'),
};
