import { __, sprintf } from '@wordpress/i18n';

/** The Adjust-points dialog's strings. Functions, not constants: `__()` must run after the locale loads. */

// Header

export const ADJUST_POINTS_TITLE = () => __('Adjust points', 'yayloyalty');

/** "Daniel Okonkwo · 9,850 points" */
export function singleCustomerHeader(name: string, balancePoints: string): string {
  return sprintf(
    /* translators: 1: the customer's name; 2: their balance in points, formatted. */
    __('%1$s · %2$s points', 'yayloyalty'),
    name,
    balancePoints,
  );
}

export const COULD_NOT_VERIFY_TITLE = () =>
  __("Couldn't verify this adjustment against this customer's history.", 'yayloyalty');
export const COULD_NOT_VERIFY_BODY = () => __('Retry before submitting.', 'yayloyalty');
export const RETRY_LABEL = () => __('Retry', 'yayloyalty');

// Direction and amount

export const DIRECTION_ADD_LABEL = () => __('Add', 'yayloyalty');
export const DIRECTION_REMOVE_LABEL = () => __('Remove', 'yayloyalty');
export const AMOUNT_LABEL = () => __('Amount', 'yayloyalty');
export const POINTS_UNIT = () => __('points', 'yayloyalty');

/** "Add 500 points" / "Remove 500 points" / plain "Adjust points" while amount is 0. */
export function submitLabel(direction: 'add' | 'remove', amount: string, isZero: boolean): string {
  if (isZero) return ADJUST_POINTS_TITLE();
  return direction === 'remove'
    ? sprintf(
        /* translators: %s: number of points, formatted. */
        __('Remove %s points', 'yayloyalty'),
        amount,
      )
    : sprintf(
        /* translators: %s: number of points, formatted. */
        __('Add %s points', 'yayloyalty'),
        amount,
      );
}

// Result panel

export const NEW_BALANCE_EMPTY = () => __('New balance: —', 'yayloyalty');

/** "New balance: 10,350 points" */
export function newBalanceLine(points: string): string {
  return sprintf(
    /* translators: %s: the new balance in points, formatted. */
    __('New balance: %s points', 'yayloyalty'),
    points,
  );
}

export function addsToLiability(points: string): string {
  return sprintf(
    /* translators: %s: number of points, formatted. */
    __('Adds %s points to your outstanding liability.', 'yayloyalty'),
    points,
  );
}
export function reducesLiability(points: string): string {
  return sprintf(
    /* translators: %s: number of points, formatted. */
    __('Reduces your outstanding liability by %s points.', 'yayloyalty'),
    points,
  );
}

/** Negative-outcome panel (this adjustment would go below zero) — the two sentences are always shown together. */
export function negativeOutcomeSpendable(name: string, coversAmount: string): string {
  return sprintf(
    /* translators: 1: the customer's name; 2: points they must earn to clear the deficit, formatted. */
    __(
      '%1$s will see 0 in their account with no mention of a negative balance, and their next %2$s points will cover the difference before they can spend anything.',
      'yayloyalty',
    ),
    name,
    coversAmount,
  );
}
export function negativeOutcomeLiability(points: string): string {
  return sprintf(
    /* translators: %s: number of points, formatted. */
    __(
      'Reduces your outstanding liability by %s points. Balances below zero are not counted as liability.',
      'yayloyalty',
    ),
    points,
  );
}

// Reason and options

export const REASON_LABEL = () => __('Reason (internal)', 'yayloyalty');
export const REASON_PLACEHOLDER = () => __('Why this adjustment is being made', 'yayloyalty');
/** Quick picks: a short chip label that fills the reason with fuller text the admin can still edit. */
export const REASON_PRESETS = () => [
  {
    label: __('Correction', 'yayloyalty'),
    text: __("Correcting an error in this customer's points balance.", 'yayloyalty'),
  },
  { label: __('Goodwill', 'yayloyalty'), text: __('Goodwill gesture for this customer.', 'yayloyalty') },
  {
    label: __('Compensation', 'yayloyalty'),
    text: __('Compensation for a problem with an order or our service.', 'yayloyalty'),
  },
  { label: __('Promotion', 'yayloyalty'), text: __('Bonus points from a store promotion.', 'yayloyalty') },
];
/** Preselected on open: the first quick pick, which fits both adding and removing. */
export const DEFAULT_REASON = () => REASON_PRESETS()[0].text;

export const ADJUST_LIFETIME_LABEL = () => __('Adjust lifetime totals too', 'yayloyalty');
export const ADJUST_LIFETIME_SUBTEXT = () => __('Turn off for balance-only changes.', 'yayloyalty');

export const SHOW_CUSTOMER_NOTE_LABEL = () => __('Custom description for the customer', 'yayloyalty');
/** Generic: the localized meta carries no store name to show. */
export const SHOW_CUSTOMER_NOTE_HELPER = () =>
  __('Customers see "Adjustment by {your store name}" unless you set one.', 'yayloyalty');
export const CUSTOMER_NOTE_PLACEHOLDER = () => __('Adjustment', 'yayloyalty');

export const NOTIFY_BY_EMAIL_LABEL = () => __('Notify the customer by email', 'yayloyalty');

// Submit hint

export const SUBMIT_HINT_PREFIX = () => __('To continue:', 'yayloyalty');
export const SUBMIT_HINT_ACKNOWLEDGE = () => __('acknowledge the total', 'yayloyalty');
export const SUBMIT_HINT_REASON = () => __('add a reason', 'yayloyalty');

export const FOOTER_NOTICE = () => __("Adjustments can't be edited or deleted.", 'yayloyalty');

// Already negative

export const NEGATIVE_BALANCE_TITLE = () => __('This balance is below zero', 'yayloyalty');
export const NEGATIVE_BALANCE_BODY = () =>
  __(
    'Points you add here clear the deficit first — they do not become spendable until the balance passes zero.',
    'yayloyalty',
  );
export const AFTER_ADJUSTMENT_TITLE = () => __('After this adjustment', 'yayloyalty');
export function stillBelowZeroBadge(): string {
  return __('still below zero · nothing spendable yet', 'yayloyalty');
}
/** "Tom needs 400 more points before he can use any. To clear it outright, add 1,000." */
export function needsMoreBeforeSpendable(name: string, needed: string, toClear: string): string {
  return sprintf(
    /* translators: 1: the customer's name; 2: points still needed after this adjustment, formatted; 3: points that would clear the deficit now, formatted. */
    __('%1$s needs %2$s more points before they can use any. To clear it outright, add %3$s.', 'yayloyalty'),
    name,
    needed,
    toClear,
  );
}
export const NEVER_SHOWN_NEGATIVE = () =>
  __('Their rewards page shows 0 either way — a customer is never shown a negative balance.', 'yayloyalty');

// Balance changed while open

export function staleHeaderSubtitle(openedBalance: string): string {
  return sprintf(
    /* translators: %s: the balance in points when the dialog opened, formatted. */
    __('was %s points when this opened', 'yayloyalty'),
    openedBalance,
  );
}
export function staleBannerTitle(name: string): string {
  return sprintf(
    /* translators: %s: the customer's name. */
    __("%s's balance changed while this was open", 'yayloyalty'),
    name,
  );
}
export function staleBannerBody(newBalance: string, openedBalance: string): string {
  return sprintf(
    /* translators: 1: the current balance in points, formatted; 2: the balance when the dialog opened, formatted. */
    __('They now hold %1$s, not the %2$s this dialog opened with.', 'yayloyalty'),
    newBalance,
    openedBalance,
  );
}
export const RELOAD_BALANCE_LABEL = () => __('Reload the balance', 'yayloyalty');
export const STALE_KEEPS_VALUES = () => __('your amount and reason are kept', 'yayloyalty');
export const STALE_BLOCKS_SAVING = () =>
  __('Saving is blocked until the balance is reloaded. Nothing has been written yet.', 'yayloyalty');

/** Single-customer result toasts. The bulk run words its own. */
export function adjustPointsSuccessToast(name: string): string {
  return sprintf(
    /* translators: %s: the customer's name. */
    __('Adjusted points for %s.', 'yayloyalty'),
    name,
  );
}
export const ADJUST_POINTS_ERROR_FALLBACK = () => __('Could not adjust points for this customer.', 'yayloyalty');
