import { __, sprintf } from '@wordpress/i18n';

/**
 * Every sentence in the Adjust-points dialog (single 2a-2d/3a-3b and bulk
 * 2g layouts). Flat exported functions, every entry a function — `__()`
 * must run after the locale loads, so nothing here is a module-scope
 * constant. Follows `customer-drawer.copy.ts`'s style/section convention.
 */

// ─── Header ──────────────────────────────────────────

export const ADJUST_POINTS_TITLE = () => __('Adjust points', 'yayloyalty');

/** "Daniel Okonkwo · 9,850 points ($98.50)" */
export function singleCustomerHeader(name: string, balancePoints: string, balanceMoney: string | null): string {
  return balanceMoney
    ? sprintf(__('%1$s · %2$s points (%3$s)', 'yayloyalty'), name, balancePoints, balanceMoney)
    : sprintf(__('%1$s · %2$s points', 'yayloyalty'), name, balancePoints);
}

export const COULD_NOT_VERIFY_TITLE = () =>
  __("Couldn't verify this adjustment against this customer's history.", 'yayloyalty');
export const COULD_NOT_VERIFY_BODY = () => __('Retry before submitting.', 'yayloyalty');
export const RETRY_LABEL = () => __('Retry', 'yayloyalty');

// ─── Direction + amount ──────────────────────────────

export const DIRECTION_ADD_LABEL = () => __('Add', 'yayloyalty');
export const DIRECTION_REMOVE_LABEL = () => __('Remove', 'yayloyalty');
export const AMOUNT_LABEL = () => __('Amount', 'yayloyalty');
export const POINTS_UNIT = () => __('points', 'yayloyalty');

/** "Add 500 points" / "Remove 500 points" / plain "Adjust points" while amount is 0. */
export function submitLabel(direction: 'add' | 'remove', amount: string, isZero: boolean): string {
  if (isZero) return ADJUST_POINTS_TITLE();
  return direction === 'remove'
    ? sprintf(__('Remove %s points', 'yayloyalty'), amount)
    : sprintf(__('Add %s points', 'yayloyalty'), amount);
}

// ─── Result panel ────────────────────────────────────

export const NEW_BALANCE_EMPTY = () => __('New balance: —', 'yayloyalty');

/** "New balance: 10,350 points ($103.50)" */
export function newBalanceLine(points: string, money: string | null): string {
  return money
    ? sprintf(__('New balance: %1$s points (%2$s)', 'yayloyalty'), points, money)
    : sprintf(__('New balance: %s points', 'yayloyalty'), points);
}

export function addsToLiability(money: string): string {
  return sprintf(__('Adds %s to your outstanding liability.', 'yayloyalty'), money);
}
export function reducesLiability(money: string): string {
  return sprintf(__('Reduces your outstanding liability by %s.', 'yayloyalty'), money);
}

/** 2c negative-outcome panel — the two sentences are always shown together. */
export function negativeOutcomeSpendable(name: string, coversAmount: string): string {
  return sprintf(
    __(
      '%1$s will see 0 in their account with no mention of a negative balance, and their next %2$s points will cover the difference before they can spend anything.',
      'yayloyalty',
    ),
    name,
    coversAmount,
  );
}
export function negativeOutcomeLiability(money: string): string {
  return sprintf(
    __('Reduces your outstanding liability by %s. Balances below zero are not counted as liability.', 'yayloyalty'),
    money,
  );
}

// ─── Typo guard (2d) ─────────────────────────────────

export function typoGuardWarning(amount: string, lifetimeEarned: string): string {
  return sprintf(
    __(
      "%1$s points is more than three times this customer's lifetime total (%2$s). Re-enter the amount to confirm.",
      'yayloyalty',
    ),
    amount,
    lifetimeEarned,
  );
}
export const TYPO_GUARD_EXPLANATION = () =>
  __("We ask twice for anything over three times a customer's lifetime total.", 'yayloyalty');
export const CONFIRM_AMOUNT_LABEL = () => __('Re-enter the amount', 'yayloyalty');
export function typoGuardPlaceholder(amount: string): string {
  return sprintf(__('Type %s again', 'yayloyalty'), amount);
}
export function typoGuardNewBalance(points: string, money: string | null): string {
  return money
    ? sprintf(__('New balance would be %1$s points (%2$s)', 'yayloyalty'), points, money)
    : sprintf(__('New balance would be %s points', 'yayloyalty'), points);
}

// ─── Reason + options ────────────────────────────────

export const REASON_LABEL = () => __('Reason (internal)', 'yayloyalty');
export const REASON_HELPER = () =>
  __("Only staff can see this. It's recorded against your account in the customer's history.", 'yayloyalty');
export const REASON_PLACEHOLDER = () => __('Why this adjustment is being made', 'yayloyalty');

export const ADJUST_LIFETIME_LABEL = () => __('Adjust lifetime totals too', 'yayloyalty');
export const ADJUST_LIFETIME_SUBTEXT = () =>
  __(
    "Leave this on when you're granting or correcting earned points. Turn it off for balance-only changes.",
    'yayloyalty',
  );

export const SHOW_CUSTOMER_NOTE_LABEL = () => __('Also show a description to the customer', 'yayloyalty');
/**
 * No store-name field exists on `window.yayLoyaltyMeta` (`types/localize.type.ts`)
 * today, so this always ships the generic fallback the plan names for that case
 * rather than inventing one.
 */
export const CUSTOMER_NOTE_PREVIEW = () => __('The customer will see: "Adjustment"', 'yayloyalty');
export const CUSTOMER_NOTE_PLACEHOLDER = () => __('Adjustment', 'yayloyalty');

export const NOTIFY_BY_EMAIL_LABEL = () => __('Notify the customer by email', 'yayloyalty');

export const FOOTER_NOTICE = () =>
  __(
    "Adjustments can't be edited or deleted — only offset by another adjustment. Any adjustment also resets the customer's points expiry date.",
    'yayloyalty',
  );

// ─── 3a: already negative ────────────────────────────

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
    __('%1$s needs %2$s more points before they can use any. To clear it outright, add %3$s.', 'yayloyalty'),
    name,
    needed,
    toClear,
  );
}
export const NEVER_SHOWN_NEGATIVE = () =>
  __('Their rewards page shows 0 either way — a customer is never shown a negative balance.', 'yayloyalty');

// ─── 3b: balance changed while open ──────────────────

export function staleHeaderSubtitle(openedBalance: string): string {
  return sprintf(__('was %s points when this opened', 'yayloyalty'), openedBalance);
}
export function staleBannerTitle(name: string): string {
  return sprintf(__("%s's balance changed while this was open", 'yayloyalty'), name);
}
export function staleBannerBody(newBalance: string, openedBalance: string): string {
  return sprintf(__('They now hold %1$s, not the %2$s this dialog opened with.', 'yayloyalty'), newBalance, openedBalance);
}
export const RELOAD_BALANCE_LABEL = () => __('Reload the balance', 'yayloyalty');
export const STALE_KEEPS_VALUES = () => __('your amount and reason are kept', 'yayloyalty');
export const STALE_BLOCKS_SAVING = () =>
  __('Saving is blocked until the balance is reloaded. Nothing has been written yet.', 'yayloyalty');
export const IF_SAVED_NOW_TITLE = () => __('If you saved this now', 'yayloyalty');
/** "Daniel would be left with 270 points rather than 0." */
export function ifSavedNowBody(name: string, wouldHavePoints: string, insteadOfPoints: string): string {
  return sprintf(
    __('%1$s would be left with %2$s points rather than %3$s.', 'yayloyalty'),
    name,
    wouldHavePoints,
    insteadOfPoints,
  );
}
