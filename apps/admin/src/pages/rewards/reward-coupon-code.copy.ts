import { __ } from '@wordpress/i18n';

/** The read-only Coupon code field's words, shared by the built-in card and the Pro reward editor. */
export function rewardCouponCodeCopy(): { label: string; description: string } {
  return {
    label: __('Coupon code', 'yayloyalty'),
    description: __(
      'Orders that use this reward show this code on their coupon lines. It never changes.',
      'yayloyalty',
    ),
  };
}
