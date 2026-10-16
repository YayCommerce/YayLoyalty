import * as v from 'valibot';

import { api } from '@/lib/api/api';

/**
 * Mirrors YayLoyalty\Pro\Controllers\CampaignPreviewRestController::preview(), the source of truth.
 * Client for POST /campaigns/{id}/preview-conditions.
 */

export const conditionResultSchema = v.object({
  type: v.string(),
  operator: v.string(),
  value: v.unknown(),
  matched: v.boolean(),
  /** Why it matched or did not: ConditionsEvaluator's reason codes (matched | no_overlap | out_of_range | no_customer | needs_order | malformed_value | …), labelled by describeReason(). */
  reason: v.string(),
  /** Present for numeric conditions: the customer's actual figure. */
  actual: v.optional(v.number()),
});

export const conditionsPreviewSchema = v.object({
  campaign_id: v.pipe(v.number(), v.integer()),
  user_id: v.pipe(v.number(), v.integer()),
  matches: v.boolean(),
  conditions: v.array(conditionResultSchema),
});

export type ConditionResult = v.InferOutput<typeof conditionResultSchema>;
export type ConditionsPreview = v.InferOutput<typeof conditionsPreviewSchema>;

export async function previewConditions(campaignId: number, userId: number): Promise<ConditionsPreview> {
  const payload = await api.post(`campaigns/${campaignId}/preview-conditions`, { json: { user_id: userId } }).json();

  return v.parse(conditionsPreviewSchema, payload);
}
