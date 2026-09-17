import { __ } from '@wordpress/i18n';

import { CampaignConditionKind, CampaignDisplayStatus, CampaignTrigger } from '@/lib/schema/campaign.schema';

/**
 * Campaign presentation strings shared across `CampaignTable` and the editor
 * (`pro/campaigns/editor/`). Extracted from `CampaignTable.tsx` (phase-03 of
 * the campaign editor plan) rather than duplicated — the editor's Trigger and
 * Basics sections (phase-04) reuse `triggerLabel()`/`statusLabel()` verbatim,
 * and the priority ladder (phase-05) reuses `triggerGroup()`.
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

/**
 * The per-type condition label map — built for the campaign editor's
 * Conditions section (phase-05) and reused by `CampaignTable.tsx`'s
 * `humanizeConditionType()` fallback. Covers every offerable type from
 * `CAMPAIGN_CONDITION_TYPES` — `tier` is deliberately absent, matching that
 * list.
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
