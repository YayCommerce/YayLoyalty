import { __, sprintf } from '@wordpress/i18n';

import { parseWPCurrency } from '@/lib/helpers/format.helper';
import type { CampaignTrigger } from '@/lib/schema/campaign.schema';

/**
 * Campaign meta-line labels composed client-side from the real
 * `trigger` / `config` / `conditions` — the campaign wire shape carries no
 * presentation strings by design. Shared by CampaignTable (Campaigns page)
 * and DashboardTopCampaigns (Pro dashboard); no new label logic lives here.
 */

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

  // Every other trigger's config carries a flat `points` — see Campaign::config_schema() / YayLoyaltyPro::add_config_keys().
  return sprintf(/* translators: %s: points awarded. */ __('%s points', 'yayloyalty'), String(config.points ?? 0));
}

export function conditionsLabel(conditions: unknown): string {
  const list = Array.isArray(conditions) ? conditions : [];

  if (list.length === 0) {
    return __('No conditions', 'yayloyalty');
  }

  // Mirrors Enums\CampaignConditionType's vocabulary loosely (slug -> title
  // case) rather than a hardcoded label per type — good enough for a table
  // column; a merchant-facing editor would need the real per-type map.
  return list
    .map((condition) => humanizeConditionType(String((condition as { type?: unknown }).type ?? '')))
    .join(', ');
}

function humanizeConditionType(type: string): string {
  return type.replace(/_/g, ' ').replace(/^\w/, (char) => char.toUpperCase());
}
