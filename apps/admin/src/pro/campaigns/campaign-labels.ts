import { __, sprintf } from '@wordpress/i18n';

import { parseWPCurrency } from '@/lib/helpers/format.helper';
import {
  CAMPAIGN_CONDITION_TYPES,
  CampaignConditionKind,
  CampaignDisplayStatus,
  CampaignTrigger,
} from '@/lib/schema/campaign.schema';

/**
 * Campaign presentation strings shared across `CampaignTable`, the editor
 * (`pro/campaigns/editor/`), and the Pro dashboard's `DashboardTopCampaigns`
 * — the wire shape carries no presentation strings by design, so every label
 * here is derived client-side from `trigger`/`config`/`conditions`/`status`.
 */

/** A campaign only ever competes with others sharing its group — see triggerGroup(). */
export type CampaignGroupKey = 'purchase' | 'signup' | 'review' | 'other';

/**
 * Campaigns only compete with others sharing a group. `purchase`, `signup`
 * and `review` each get their own; every other trigger — birthday and the
 * remaining Pro triggers — is rare enough today to share a catch-all rather
 * than earn a group of one each.
 */
export function triggerGroup(trigger: CampaignTrigger): CampaignGroupKey {
  if (trigger === 'purchase' || trigger === 'signup' || trigger === 'review') {
    return trigger;
  }

  return 'other';
}

export function triggerLabel(trigger: CampaignTrigger): string {
  switch (trigger) {
    case 'purchase':
      return __('Order placed', 'yayloyalty');
    case 'signup':
      return __('Account created', 'yayloyalty');
    case 'review':
      return __('Review published', 'yayloyalty');
    case 'birthday':
      return __('Birthday', 'yayloyalty');
    case 'first_order':
      return __('First order', 'yayloyalty');
    case 'order_count':
      return __('Order count reached', 'yayloyalty');
    case 'spend_milestone':
      return __('Spend milestone reached', 'yayloyalty');
    default:
      return trigger;
  }
}

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

  return list
    .map((condition) => humanizeConditionType(String((condition as { type?: unknown }).type ?? '')))
    .join(', ');
}

/**
 * Backed by the real per-type map (`conditionTypeLabel()` below — built for
 * the campaign editor's Conditions section, phase-05). Falls back to a
 * slug-to-title-case approximation only for a type this build doesn't
 * recognise (e.g. `tier`, or a stored type from a future schema version) so a
 * stale row never renders blank.
 */
function humanizeConditionType(type: string): string {
  const known = CAMPAIGN_CONDITION_TYPES.find((kind) => kind === type);

  if (known) {
    return conditionTypeLabel(known);
  }

  return type.replace(/_/g, ' ').replace(/^\w/, (char) => char.toUpperCase());
}

/**
 * The per-type condition label map — built for the campaign editor's
 * Conditions section and reused by `conditionsLabel()`'s fallback
 * above. Covers every offerable type from `CAMPAIGN_CONDITION_TYPES` —
 * `tier` is deliberately absent, matching that list.
 */
export function conditionTypeLabel(type: CampaignConditionKind): string {
  switch (type) {
    case 'user_role':
      return __('Customer role', 'yayloyalty');
    case 'customer_ids':
      return __('Specific customers', 'yayloyalty');
    case 'currency':
      return __('Order currency', 'yayloyalty');
    case 'payment_method':
      return __('Payment method', 'yayloyalty');
    case 'country':
      return __('Country', 'yayloyalty');
    case 'product_in_cart':
      return __('Product in cart', 'yayloyalty');
    case 'category_in_cart':
      return __('Product category in cart', 'yayloyalty');
    case 'lifetime_points':
      return __('Lifetime points', 'yayloyalty');
    case 'order_count':
      return __('Order count', 'yayloyalty');
    case 'lifetime_spend':
      return __('Lifetime spend', 'yayloyalty');
    default:
      return type;
  }
}

export function statusLabel(status: CampaignDisplayStatus): string {
  switch (status) {
    case 'active':
      return __('Active', 'yayloyalty');
    case 'scheduled':
      return __('Scheduled', 'yayloyalty');
    case 'paused':
      return __('Paused', 'yayloyalty');
    case 'draft':
      return __('Draft', 'yayloyalty');
    case 'archived':
      return __('Archived', 'yayloyalty');
    case 'ended':
      return __('Ended', 'yayloyalty');
  }
}
