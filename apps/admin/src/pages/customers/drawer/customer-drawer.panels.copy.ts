import { __, sprintf } from '@wordpress/i18n';

/**
 * Copy for the drawer's three header-level edge-state panels — deleted (4a),
 * expiring (4b), negative balance (5a). Split out of `customer-drawer.copy.ts`
 * once that file neared the 200-line cap (F09 Wave B phase 02) — the three
 * panels share one composition (see `CustomerDrawer.tsx`) and share this
 * file rather than each reopening the main copy module.
 *
 * Same rules as the main copy module: flat exported functions, every entry a
 * function (never a module-scope constant — `__()` must run after the
 * locale loads), pure — no React, no fetch.
 */

// ─── 4a: deleted account ──────────────────────────────

/** "This WordPress user was deleted on 8 September 2026" */
export function deletedBannerTitle(date: string): string {
  return sprintf(__('This WordPress user was deleted on %s', 'yayloyalty'), date);
}
/** "The account is gone, but the ledger is not: 24 entries are kept so your
 *  liability, your reports and any refund on their past orders still add up." */
export function deletedBannerBody(keptEntries: string): string {
  return sprintf(
    __(
      'The account is gone, but the ledger is not: %s entries are kept so your liability, your reports and any refund on their past orders still add up.',
      'yayloyalty',
    ),
    keptEntries,
  );
}

export const WHAT_CAN_STILL_BE_DONE_TITLE = () => __('What can still be done', 'yayloyalty');
export const ACTION_ANONYMISE = () => __('Anonymise the record', 'yayloyalty');
/** `DELETED_ACCOUNT_ACTION_REASON` (main copy module) is spend-specific
 *  wording ("nobody to spend the points") that fits Adjust points but reads
 *  as a non-sequitur beside a disabled Reset expiry button — extending a
 *  deadline isn't about spending. Same underlying cause (no owner left),
 *  worded generally enough to fit both. */
export const DELETED_RESET_EXPIRY_REASON = () => __('There is no owner left to extend anything for.', 'yayloyalty');
/** Distinct from `ACTION_EXPORT_LEDGER`'s disabled reason elsewhere in the
 *  drawer — "no job exists yet" and "not built yet" are different facts. */
export const DELETED_EXPORT_REASON = () => __('No export job exists yet.', 'yayloyalty');
export const DELETED_ANONYMISE_REASON = () =>
  __('Not built yet — a separate feature from this drawer.', 'yayloyalty');

export const LOOSE_ENDS_TITLE = () => __('Loose ends this leaves', 'yayloyalty');
export const LOOSE_ENDS_VOID_COUPONS_LABEL = () => __('Unused coupons, now void', 'yayloyalty');
export const LOOSE_ENDS_CREDITED_REFERRALS_LABEL = () => __('Referrals they were credited for', 'yayloyalty');
export const LOOSE_ENDS_COUNTED_LABEL = () => __('Counted in the member total?', 'yayloyalty');
export const YES_LABEL = () => __('yes', 'yayloyalty');
export const NO_LABEL = () => __('no', 'yayloyalty');

/** "1 · $5 off" — `example` is one illustrative label, not a list. */
export function voidCouponsValue(count: string, example: string | null): string {
  return example ? sprintf(__('%1$s · %2$s', 'yayloyalty'), count, example) : count;
}
/** "2 · kept" */
export function creditedReferralsValue(count: string): string {
  return sprintf(__('%s · kept', 'yayloyalty'), count);
}
export function looseEndsLiabilitySentence(balance: string): string {
  return sprintf(
    __('Their %s points stay in your outstanding liability until the record is anonymised.', 'yayloyalty'),
    balance,
  );
}
