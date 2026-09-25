import { __, _n, sprintf } from '@wordpress/i18n';

import { parseWPCurrency, parseWPDate } from '@/lib/helpers/format.helper';
import {
  Campaign,
  CAMPAIGN_CONDITION_TYPES,
  CampaignConditionKind,
  CampaignDisplayStatus,
  CampaignTrigger,
} from '@/lib/schema/campaign.schema';

/** Campaign presentation strings derived from the real fields; the wire carries none. */

/** The built-in (default) campaign for a trigger: what a non-built-in falls back to. undefined while the list loads. */
export function findBuiltinCampaign(campaigns: Campaign[] | undefined, trigger: CampaignTrigger): Campaign | undefined {
  return campaigns?.find((candidate) => candidate.is_builtin && candidate.trigger === trigger);
}

/** A campaign only competes within its group; see triggerGroup(). */
export type CampaignGroupKey = 'purchase' | 'signup' | 'review' | 'other';

/** purchase/signup/review each get a group; the rarer Pro triggers share a catch-all. */
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
  if (trigger === 'purchase' && config.mode === 'multiplier') {
    return sprintf(
      /* translators: %s: the multiplier, e.g. "2". */ __('×%s points', 'yayloyalty'),
      String(config.multiplier ?? 1),
    );
  }

  if (trigger === 'purchase') {
    const rate = Number(config.points_per_unit ?? 0);

    return sprintf(
      /* translators: 1: points earned per unit of currency spent; 2: one unit of the store currency, formatted. */
      _n('%1$s pt per %2$s', '%1$s pts per %2$s', rate, 'yayloyalty'),
      String(rate),
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

  return list
    .map((condition) => humanizeConditionType(String((condition as { type?: unknown }).type ?? '')))
    .join(', ');
}

/** Falls back to slug-to-title-case for a type this build does not recognise, so a stale row never renders blank. */
function humanizeConditionType(type: string): string {
  const known = CAMPAIGN_CONDITION_TYPES.find((kind) => kind === type);

  if (known) {
    return conditionTypeLabel(known);
  }

  return type.replace(/_/g, ' ').replace(/^\w/, (char) => char.toUpperCase());
}

/** Label per offerable type; `tier` is absent, matching CAMPAIGN_CONDITION_TYPES. */
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

export function scheduleLabel(startsAt: string | null, endsAt: string | null): string {
  if (!startsAt && !endsAt) {
    return __('Always on', 'yayloyalty');
  }

  if (startsAt && endsAt) {
    return `${parseWPDate(startsAt)} – ${parseWPDate(endsAt)}`;
  }

  if (startsAt) {
    return sprintf(/* translators: %s: a formatted date. */ __('From %s', 'yayloyalty'), parseWPDate(startsAt));
  }

  return sprintf(/* translators: %s: a formatted date. */ __('Until %s', 'yayloyalty'), parseWPDate(endsAt as string));
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
