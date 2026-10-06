import { __ } from '@wordpress/i18n';

import { Reward } from '@/lib/schema/reward.schema';

/** Reward labels Lite renders too; the Pro catalog's labels stay in pro/rewards/reward-labels.ts so Lite doesn't ship them. */

export function numberConfig(config: Reward['config'], key: string): number | null {
  const value = config[key];

  return typeof value === 'number' ? value : null;
}

/** The built-in slider's subtitle. */
export function flexibleDiscountSubtitle(): string {
  return __('The customer chooses how many points to use at checkout', 'yayloyalty');
}
