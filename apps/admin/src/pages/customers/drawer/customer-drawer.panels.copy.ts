import { __, sprintf } from '@wordpress/i18n';

import { formatPoints } from '@/lib/helpers/points.helper';

/**
 * Strings for the drawer's deleted, expiring and negative-balance panels.
 * Functions, not constants: `__()` must run after the locale loads.
 */

// Deleted account

/** "This WordPress user was deleted on 8 September 2026" */
export function deletedBannerTitle(date: string): string {
  return sprintf(
    /* translators: %s: the deletion date, formatted. */
    __('This WordPress user was deleted on %s', 'yayloyalty'),
    date,
  );
}
export function deletedBannerBody(keptEntries: string): string {
  return sprintf(
    /* translators: %s: number of ledger entries kept, formatted. */
    __(
      'The account is gone, but the ledger is not: %s entries are kept so your liability, your reports and any refund on their past orders still add up.',
      'yayloyalty',
    ),
    keptEntries,
  );
}

export const WHAT_CAN_STILL_BE_DONE_TITLE = () => __('What can still be done', 'yayloyalty');
export const ACTION_ANONYMISE = () => __('Anonymise the record', 'yayloyalty');
/** Export stays live for a deleted account: the history outlives the owner. */
export const DELETED_EXPORT_REASON = () => __('Download the full history as a CSV.', 'yayloyalty');
export const DELETED_ANONYMISE_REASON = () => __('Not built yet — a separate feature from this drawer.', 'yayloyalty');

export const LOOSE_ENDS_TITLE = () => __('Loose ends this leaves', 'yayloyalty');
export const LOOSE_ENDS_VOID_COUPONS_LABEL = () => __('Unused coupons, now void', 'yayloyalty');
export const LOOSE_ENDS_COUNTED_LABEL = () => __('Counted in the member total?', 'yayloyalty');
export const YES_LABEL = () => __('yes', 'yayloyalty');
export const NO_LABEL = () => __('no', 'yayloyalty');
/** Shown for a null loose-ends fact, which is unanswered, not hidden. */
export const LOOSE_END_NOT_TRACKED_YET = () => __('Not tracked yet', 'yayloyalty');

/** "1 · $5 off". `example` is one illustrative label, not a list. */
export function voidCouponsValue(voidCoupons: { count: number; example: string | null } | null): string {
  if (!voidCoupons) return LOOSE_END_NOT_TRACKED_YET();

  const count = formatPoints(voidCoupons.count);
  return voidCoupons.example
    ? sprintf(
        /* translators: 1: number of void coupons, formatted; 2: one coupon's label, such as "$5 off". */
        __('%1$s · %2$s', 'yayloyalty'),
        count,
        voidCoupons.example,
      )
    : count;
}
export function countedInMembersValue(counted: boolean | null): string {
  if (null === counted) return LOOSE_END_NOT_TRACKED_YET();
  return counted ? YES_LABEL() : NO_LABEL();
}
export function looseEndsLiabilitySentence(balance: string): string {
  return sprintf(
    /* translators: %s: the balance in points, formatted. */
    __('Their %s points stay in your outstanding liability until the record is anonymised.', 'yayloyalty'),
    balance,
  );
}

// Expiring points

/** "6,300 of 9,850 points expire on 14 August 2027" */
export function expiringBannerTitle(expiringPoints: string, balance: string, date: string): string {
  return sprintf(
    /* translators: 1: points expiring, formatted; 2: the balance in points, formatted; 3: the expiry date, formatted. */
    __('%1$s of %2$s points expire on %3$s', 'yayloyalty'),
    expiringPoints,
    balance,
    date,
  );
}
/**
 * "In 12 days. That is $63.00 about to be lost, and 64% of the balance."
 * Without `sharePercent` (a balance at or below 0) the percentage clause is dropped, never shown as "0%".
 */
export function expiringBannerBody(days: number, money: string, sharePercent: string | null): string {
  if (null === sharePercent) {
    return sprintf(
      /* translators: %1$d: days until expiry, %2$s: money value */
      __('In %1$d days. That is %2$s about to be lost.', 'yayloyalty'),
      days,
      money,
    );
  }

  return sprintf(
    /* translators: %1$d: days until expiry, %2$s: money value, %3$s: percentage of balance */
    __('In %1$d days. That is %2$s about to be lost, and %3$s%% of the balance.', 'yayloyalty'),
    days,
    money,
    sharePercent,
  );
}
export function expiringReminderSentence(sentDate: string, dueDate: string, windowDays: string): string {
  return sprintf(
    /* translators: 1: the date the 30-day reminder was sent, formatted; 2: the next reminder's window in days; 3: the date it is due, formatted. */
    __('The 30-day reminder went out on %1$s; the %2$s-day one is due on %3$s.', 'yayloyalty'),
    sentDate,
    windowDays,
    dueDate,
  );
}

export const ACTION_EXEMPT_FROM_EXPIRY = () => __('Exempt from expiry', 'yayloyalty');
export const ACTION_SEND_REMINDER_NOW = () => __('Send the reminder now', 'yayloyalty');

export function expiringInDaysLabel(days: number): string {
  return sprintf(
    /* translators: %d: days until the points expire. */
    __('Expiring in %d days', 'yayloyalty'),
    days,
  );
}
export function safeBeyondLabel(year: string): string {
  return sprintf(
    /* translators: %s: the year the expiring points expire. */
    __('Safe beyond %s', 'yayloyalty'),
    year,
  );
}

export const EXPIRY_BATCH_TITLE = () => __('Points, by the batch they were earned in', 'yayloyalty');
export const EXPIRY_BATCH_COLUMN_EARNED = () => __('Earned', 'yayloyalty');
export const EXPIRY_BATCH_COLUMN_EXPIRES = () => __('Expires', 'yayloyalty');
/** The inactivity model has no lots, so there is nothing to batch. */
export const EXPIRY_FREE_EXPLANATION = () =>
  __(
    'On the free inactivity model there are no batches: the whole balance moves together, and points expire together too.',
    'yayloyalty',
  );

// Negative balance

export const NEGATIVE_BALANCE_HEADING = () => __('Why this balance is negative', 'yayloyalty');

/** The three-step refund story, composed from each leg's fields, never a sentence from the API. */
export function negativeBalanceFactEarned(firstName: string, points: string, orderId: string, date: string): string {
  return sprintf(
    /* translators: 1: the customer's first name; 2: points earned, formatted; 3: the order number; 4: the date, formatted. */
    __('%1$s earned %2$s points on order #%3$s on %4$s.', 'yayloyalty'),
    firstName,
    points,
    orderId,
    date,
  );
}
export function negativeBalanceFactSpent(points: string, orderId: string, value: string): string {
  return sprintf(
    /* translators: 1: points spent, formatted; 2: the order number; 3: the discount's value, formatted. */
    __('They spent all %1$s of them on order #%2$s — a %3$s discount already used.', 'yayloyalty'),
    points,
    orderId,
    value,
  );
}
export function negativeBalanceFactReversed(orderId: string, points: string): string {
  return sprintf(
    /* translators: 1: the refunded order's number; 2: points reversed, formatted. */
    __(
      'Order #%1$s was then refunded, so the %2$s points it issued were reversed. There was nothing left to take back, so the balance went below zero.',
      'yayloyalty',
    ),
    orderId,
    points,
  );
}
/** `shape: 'other'`: an adjustment or an expiry, not a refund, so one sentence replaces the numbered story. */
export function negativeBalanceGenericExplanation(firstName: string, deficit: string): string {
  return sprintf(
    /* translators: 1: the customer's first name; 2: points below zero, formatted. */
    __('%1$s’s balance went %2$s points below zero — an earlier change reversed points already spent.', 'yayloyalty'),
    firstName,
    deficit,
  );
}

export function negativeWhatCustomerSeesTitle(firstName: string): string {
  return sprintf(
    /* translators: %s: the customer's first name. */
    __('What %s sees', 'yayloyalty'),
    firstName,
  );
}
export const NEGATIVE_WHAT_CUSTOMER_SEES_BODY = () =>
  __(
    '0 points. No debt, no negative number, no message about owing anything — their rewards page reads as if they have an empty balance.',
    'yayloyalty',
  );

export const NEGATIVE_WHAT_HAPPENS_NEXT_TITLE = () => __('What happens next', 'yayloyalty');
export function negativeWhatHappensNextBody(deficit: string): string {
  return sprintf(
    /* translators: %s: the deficit in points, formatted. */
    __(
      'The first %s points earned clear the deficit before anything becomes spendable. Nothing expires while the balance is negative, and it is not counted in your liability.',
      'yayloyalty',
    ),
    deficit,
  );
}

export const ACTION_WRITE_OFF_NEGATIVE_BALANCE = () => __('Write off the negative balance', 'yayloyalty');
export const ACTION_LEAVE_AS_IS = () => __('Leave as is', 'yayloyalty');
export function negativeWriteOffConsequence(firstName: string): string {
  return sprintf(
    /* translators: %s: the customer's first name. */
    __('Writing off sets the balance to 0 with an adjustment in %s’s ledger, naming you.', 'yayloyalty'),
    firstName,
  );
}
