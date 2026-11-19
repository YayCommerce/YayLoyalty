import { __, sprintf } from '@wordpress/i18n';

import { formatCount } from '@/lib/helpers/points.helper';
import type { ReferralStatus } from './referrals.schema';

/** The Referrals tab's strings. Functions, not constants: `__()` must run after the locale loads. */
export const REFERRALS_COPY = {
  tab: () => __('Referrals', 'yayloyalty'),
  description: (total: number, rewarded: number) =>
    /* translators: 1: number of referrals, 2: number of rewarded referrals */
    sprintf(__('%1$s referrals · %2$s rewarded', 'yayloyalty'), formatCount(total), formatCount(rewarded)),
  search: () => __('Search advocate or friend', 'yayloyalty'),
  filterLabel: () => __('Filter by status', 'yayloyalty'),
  all: () => __('All', 'yayloyalty'),
  columnAdvocate: () => __('Advocate', 'yayloyalty'),
  columnFriend: () => __('Friend', 'yayloyalty'),
  columnStatus: () => __('Status', 'yayloyalty'),
  columnOrder: () => __('Order', 'yayloyalty'),
  columnValue: () => __('Value', 'yayloyalty'),
  columnReward: () => __('Reward', 'yayloyalty'),
  columnDate: () => __('Date', 'yayloyalty'),
  empty: () => __('No referrals yet. They appear here when someone signs up through a customer\'s link.', 'yayloyalty'),
  emptyFiltered: () => __('No referrals match these filters.', 'yayloyalty'),
  loadError: () => __('Could not load referrals.', 'yayloyalty'),
  /* translators: %s: the date the hold ends */
  releases: (date: string) => sprintf(__('releases %s', 'yayloyalty'), date),
  noOrderYet: () => __('no qualifying order yet', 'yayloyalty'),
  noOrderBeforeExpiry: () => __('no order before the coupon expired', 'yayloyalty'),
  orderRefunded: () => __('order refunded', 'yayloyalty'),
};

export const REFERRAL_STATUS_LABEL: Record<ReferralStatus, () => string> = {
  rewarded: () => __('Rewarded', 'yayloyalty'),
  on_hold: () => __('On hold', 'yayloyalty'),
  pending: () => __('Pending', 'yayloyalty'),
  rejected: () => __('Rejected', 'yayloyalty'),
  expired: () => __('Expired', 'yayloyalty'),
  reversed: () => __('Reversed', 'yayloyalty'),
};

/** Mirrors YayLoyalty\Pro\Enums\ReferralRejectReason::all(): the admin's reasons, then the ones the plugin sets. */
export const REJECT_REASON_LABEL: Record<string, () => string> = {
  duplicate_account: () => __('Suspected duplicate account', 'yayloyalty'),
  order_refunded: () => __('Order refunded or cancelled', 'yayloyalty'),
  not_genuine: () => __('Not a genuine referral', 'yayloyalty'),
  other: () => __('Other', 'yayloyalty'),
  self_referral: () => __('self-referral', 'yayloyalty'),
  not_qualifying: () => __("first order didn't qualify", 'yayloyalty'),
  refunded_during_hold: () => __('Order refunded during the hold', 'yayloyalty'),
  advocate_cannot_earn: () => __("advocate can't earn points", 'yayloyalty'),
};
