import { __, sprintf } from '@wordpress/i18n';

export const REFERRAL_SETTINGS_COPY = {
  programLive: () => __('Program live', 'yayloyalty'),
  programLiveHint: () => __('Off hides every storefront piece. Held referrals still release.', 'yayloyalty'),
  friendTitle: () => __('What the friend gets', 'yayloyalty'),
  discountLabel: () => __('Discount on their first order', 'yayloyalty'),
  discountTypeLegend: () => __('Discount type', 'yayloyalty'),
  fixed: () => __('Fixed', 'yayloyalty'),
  percentage: () => __('Percentage', 'yayloyalty'),
  minOrder: () => __('Minimum order', 'yayloyalty'),
  minOrderHint: () => __("0 means no minimum. The discount won't apply below it.", 'yayloyalty'),
  couponExpires: () => __('Coupon expires', 'yayloyalty'),
  couponExpiresUnit: () => __('days after signing up', 'yayloyalty'),
  advocateTitle: () => __('What the advocate gets', 'yayloyalty'),
  advocateUnit: () => __('points', 'yayloyalty'),
  advocateHint: () =>
    __('Lands in their balance like any other earning, and shows in their points history.', 'yayloyalty'),
  payoutTitle: () => __('When it pays out', 'yayloyalty'),
  holdFor: () => __('Hold for', 'yayloyalty'),
  holdUnit: () => __("days after the friend's order completes", 'yayloyalty'),
  holdHint: () =>
    __('0 pays as soon as the order completes. A refund during the hold cancels the reward.', 'yayloyalty'),
  rulesTitle: () => __('Qualifying rules', 'yayloyalty'),
  excludeOtherCoupons: () => __('Exclude orders that used another coupon', 'yayloyalty'),
  rulesNote: () => __('First order only. Self-referrals never count.', 'yayloyalty'),
  attributionTitle: () => __('Attribution', 'yayloyalty'),
  cookieWindow: () => __('Cookie window', 'yayloyalty'),
  cookieUnit: () => __('days from the click', 'yayloyalty'),
  cookieHint: () =>
    __(
      'Set when the link is opened. A friend who signs up within this window is credited to the advocate.',
      'yayloyalty',
    ),
  previewTitle: () => __('What the customer sees', 'yayloyalty'),
  save: () => __('Save changes', 'yayloyalty'),
  saveHint: () => __('Changes apply to new referrals only.', 'yayloyalty'),
  saved: () => __('Referral settings saved', 'yayloyalty'),
  saveFailed: () => __('Could not save the referral settings.', 'yayloyalty'),
  loadFailed: () => __('Could not load the referral settings.', 'yayloyalty'),
  yourLink: () => __('Your link', 'yayloyalty'),
  copyLink: () => __('Copy link', 'yayloyalty'),
  /* translators: 1: what the friend gets, e.g. "$10" or "10%", 2: the advocate's points, e.g. "1,000 points" */
  offerHeading: (discount: string, points: string) =>
    sprintf(__('Give %1$s, get %2$s', 'yayloyalty'), discount, points),
};

/**
 * The offer sentence the customer reads, worded for whether there's a minimum and a hold.
 * Whole sentences per case, so translators never see a fragment.
 */
export function offerSentence(discount: string, minimum: string | null, points: string, holdDays: number): string {
  if (minimum !== null && holdDays > 0) {
    return sprintf(
      /* translators: 1: the friend's discount, 2: the minimum order, 3: the advocate's points, 4: hold length in days */
      __(
        'Your friend gets %1$s off their first order over %2$s. You get %3$s once their order completes and clears our %4$s-day hold.',
        'yayloyalty',
      ),
      discount,
      minimum,
      points,
      String(holdDays),
    );
  }

  if (minimum !== null) {
    return sprintf(
      /* translators: 1: the friend's discount, 2: the minimum order, 3: the advocate's points */
      __(
        'Your friend gets %1$s off their first order over %2$s. You get %3$s once their order completes.',
        'yayloyalty',
      ),
      discount,
      minimum,
      points,
    );
  }

  if (holdDays > 0) {
    return sprintf(
      /* translators: 1: the friend's discount, 2: the advocate's points, 3: hold length in days */
      __(
        'Your friend gets %1$s off their first order. You get %2$s once their order completes and clears our %3$s-day hold.',
        'yayloyalty',
      ),
      discount,
      points,
      String(holdDays),
    );
  }

  return sprintf(
    /* translators: 1: the friend's discount, 2: the advocate's points */
    __('Your friend gets %1$s off their first order. You get %2$s once their order completes.', 'yayloyalty'),
    discount,
    points,
  );
}
