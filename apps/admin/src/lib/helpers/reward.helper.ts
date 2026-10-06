import { __, sprintf } from '@wordpress/i18n';

import { Reward } from '@/lib/schema/reward.schema';

/** Reward labels both builds render; the Pro catalog's own labels are in pro/rewards/reward-labels.ts. */

export function numberConfig(config: Reward['config'], key: string): number | null {
  const value = config[key];

  return typeof value === 'number' ? value : null;
}

export function typeLabel(type: Reward['type']): string {
  switch (type) {
    case 'flexible_discount':
      return __('Flexible discount', 'yayloyalty');
    case 'fixed_coupon':
      return __('Discount', 'yayloyalty');
    case 'percent_coupon':
      return __('Percentage', 'yayloyalty');
    case 'free_shipping':
      return __('Free shipping', 'yayloyalty');
    case 'free_product':
      return __('Free product', 'yayloyalty');
    default:
      return type;
  }
}

/** The line under a reward's name: what redeeming it does, derived from type and config — there is no stored description column. */
export function rewardSubtitle(reward: Reward): string {
  if (reward.type === 'flexible_discount') {
    return __('The customer chooses how many points to use at checkout', 'yayloyalty');
  }

  if (reward.type === 'free_product') {
    return __('Free product · claim a coupon, then order the product', 'yayloyalty');
  }

  const expiryDays = numberConfig(reward.config, 'coupon_expiry_days');

  if (expiryDays === null) {
    return __('Single-use coupon', 'yayloyalty');
  }

  return sprintf(
    /* translators: %d: number of days the coupon stays valid after being claimed. */
    __('Single-use coupon, valid %d days after claiming', 'yayloyalty'),
    expiryDays,
  );
}
