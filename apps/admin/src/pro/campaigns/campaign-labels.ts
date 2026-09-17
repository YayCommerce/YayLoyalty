import { __, sprintf } from '@wordpress/i18n';

import { parseWPCurrency } from '@/lib/helpers/format.helper';
import type { CampaignTrigger } from '@/lib/schema/campaign.schema';

/** Campaign meta-line labels composed from the real fields; the wire carries no presentation strings. */

export function givesLabel(trigger: CampaignTrigger, config: Record<string, unknown>): string {
  if (trigger === 'purchase') {
    return config.mode === 'multiplier'
      ? sprintf(
          /* translators: %s: the multiplier, e.g. "2". */ __('×%s points', 'yayloyalty'),
          String(config.multiplier ?? 1),
        )
      : sprintf(
          /* translators: 1: points earned per unit of currency spent, 2: one unit of the store currency, formatted. */
          __('%1$s point(s) per %2$s', 'yayloyalty'),
          String(config.points_per_unit ?? 0),
          parseWPCurrency(1),
        );
  }

  // Every other trigger's config carries a flat `points`.
  return sprintf(/* translators: %s: points awarded. */ __('%s points', 'yayloyalty'), String(config.points ?? 0));
}

export function conditionsLabel(conditions: unknown): string {
  const list = Array.isArray(conditions) ? conditions : [];

  if (list.length === 0) {
    return __('No conditions', 'yayloyalty');
  }

  // Slug → title case; a merchant-facing editor would need the real per-type map.
  return list
    .map((condition) => humanizeConditionType(String((condition as { type?: unknown }).type ?? '')))
    .join(', ');
}

function humanizeConditionType(type: string): string {
  return type.replace(/_/g, ' ').replace(/^\w/, (char) => char.toUpperCase());
}
