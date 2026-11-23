import { __, _n, sprintf } from '@wordpress/i18n';

import { formatCount } from '@/lib/helpers/points.helper';

/** The Approvals tab's strings. Functions, not constants: `__()` must run after the locale loads. */
export const APPROVALS_COPY = {
  tab: () => __('Approvals', 'yayloyalty'),
  /* translators: %s: number of held referrals */
  tabWaiting: (count: number) => sprintf(__('Approvals, %s waiting', 'yayloyalty'), formatCount(count)),
  description: (count: number) =>
    sprintf(
      /* translators: %s: number of held referrals */
      _n(
        '%s referral is on hold. It pays itself when the hold ends; approve to pay early.',
        '%s referrals are on hold. They pay themselves when the hold ends; approve to pay early.',
        count,
        'yayloyalty',
      ),
      formatCount(count),
    ),
  approve: () => __('Approve', 'yayloyalty'),
  reject: () => __('Reject', 'yayloyalty'),
  approveSelected: () => __('Approve selected', 'yayloyalty'),
  rejectSelected: () => __('Reject selected', 'yayloyalty'),
  /* translators: %d: number of selected rows. */
  selected: (count: number) => sprintf(_n('%d selected', '%d selected', count, 'yayloyalty'), count),
  selectAll: () => __('Select all rows on this page', 'yayloyalty'),
  selectRow: () => __('Select row', 'yayloyalty'),
  columnWhy: () => __('Why it is here', 'yayloyalty'),
  columnHeldSince: () => __('Held since', 'yayloyalty'),
  holdEnding: () => __('Hold ending', 'yayloyalty'),
  advocateCannotEarn: () => __("Advocate can't earn", 'yayloyalty'),
  /* translators: %s: the date the referral is rejected */
  rejectsOn: (date: string) => sprintf(__('rejected automatically %s', 'yayloyalty'), date),
  /* translators: %s: the date the hold ends */
  holdEnds: (date: string) => sprintf(__('hold ends %s', 'yayloyalty'), date),
  footer: () =>
    __("The friend keeps their discount either way. Rejecting only withholds the advocate's points.", 'yayloyalty'),
  empty: () => __('Nothing waiting. Held referrals appear here until their hold ends.', 'yayloyalty'),
  /* translators: 1: points, 2: the advocate's name */
  paid: (points: number, name: string) => sprintf(__('Paid %1$s points to %2$s', 'yayloyalty'), formatCount(points), name),
  /* translators: %d: number of referrals paid */
  paidMany: (count: number) => sprintf(_n('Paid %d referral', 'Paid %d referrals', count, 'yayloyalty'), count),
  rejected: () => __('Referral rejected', 'yayloyalty'),
  /* translators: %d: number of referrals rejected */
  rejectedMany: (count: number) => sprintf(_n('%d referral rejected', '%d referrals rejected', count, 'yayloyalty'), count),
  changed: () => __('This referral was already paid or rejected. The list has been refreshed.', 'yayloyalty'),
  actionFailed: () => __('Could not update the referral.', 'yayloyalty'),
  dialogTitle: () => __('Reject this referral?', 'yayloyalty'),
  dialogTitleMany: () => __('Reject the selected referrals?', 'yayloyalty'),
  /* translators: 1: points, 2: the advocate's name */
  dialogBody: (points: number, name: string) =>
    sprintf(
      __("Rejecting withholds %1$s points from %2$s. The friend keeps their discount. This can't be undone.", 'yayloyalty'),
      formatCount(points),
      name,
    ),
  /* translators: %d: number of referrals */
  dialogBodyMany: (count: number) =>
    sprintf(
      _n(
        "Rejecting withholds the advocate's points for %d referral. The friend keeps their discount. This can't be undone.",
        "Rejecting withholds the advocates' points for %d referrals. The friends keep their discount. This can't be undone.",
        count,
        'yayloyalty',
      ),
      count,
    ),
  reasonLabel: () => __('Reason', 'yayloyalty'),
  rejecting: () => __('Rejecting…', 'yayloyalty'),
  cancel: () => __('Cancel', 'yayloyalty'),
};

/** Mirrors YayLoyalty\Pro\Enums\ReferralRejectReason::admin_choices(), in the dialog's order. */
export const ADMIN_REJECT_REASONS = ['duplicate_account', 'order_refunded', 'not_genuine', 'other'] as const;

export type AdminRejectReason = (typeof ADMIN_REJECT_REASONS)[number];
