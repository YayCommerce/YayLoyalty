import { __ } from '@wordpress/i18n';

import { Reward } from '@/lib/schema/reward.schema';

/** Reward labels Lite renders too; the Pro catalog's labels stay in pro/rewards/reward-labels.ts so Lite doesn't ship them. */

export function numberConfig(config: Reward['config'], key: string): number | null {
  const value = config[key];

  return typeof value === 'number' ? value : null;
}

/** The built-in flexible discount's subtitle, by its redemption mode; a row without one reads as auto max. */
export function flexibleDiscountSubtitle(config: Reward['config']): string {
  return config.redemption_mode === 'free_input'
    ? __('The customer chooses how many points to use at checkout', 'yayloyalty')
    : __('The most points the order allows are applied at checkout', 'yayloyalty');
}
