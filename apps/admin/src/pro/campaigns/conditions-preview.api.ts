import { z } from 'zod';

import { api } from '@/lib/api/api';

/**
 * Mirrors YayLoyalty\Pro\Controllers\CampaignPreviewRestController::preview(), the source of truth.
 * Client for POST /campaigns/{id}/preview-conditions.
 */

export const conditionResultSchema = z.object({
  type: z.string(),
  operator: z.string(),
  value: z.unknown(),
  matched: z.boolean(),
  /** Why it matched or did not: ConditionsEvaluator's reason codes (matched | no_overlap | out_of_range | no_customer | needs_order | malformed_value | …), labelled by describeReason(). */
  reason: z.string(),
  /** Present for numeric conditions: the customer's actual figure. */
  actual: z.number().optional(),
});

export const conditionGroupResultSchema = z.object({
  match: z.string(),
  matched: z.boolean(),
  conditions: z.array(conditionResultSchema),
});

export const conditionsPreviewSchema = z.object({
  campaign_id: z.number().int(),
  user_id: z.number().int(),
  matches: z.boolean(),
  /** How the groups combine: all | any. */
  match: z.string(),
  groups: z.array(conditionGroupResultSchema),
});

export type ConditionResult = z.infer<typeof conditionResultSchema>;
export type ConditionsPreview = z.infer<typeof conditionsPreviewSchema>;

export async function previewConditions(campaignId: number, userId: number): Promise<ConditionsPreview> {
  const payload = await api.post(`campaigns/${campaignId}/preview-conditions`, { json: { user_id: userId } }).json();

  return conditionsPreviewSchema.parse(payload);
}
