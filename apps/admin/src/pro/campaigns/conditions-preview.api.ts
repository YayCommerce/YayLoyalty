import { z } from 'zod';

import { api } from '@/lib/api/api';

/** Client for POST /campaigns/{id}/preview-conditions, mirroring CampaignPreviewRestController::preview(). */

export const conditionResultSchema = z.object({
  type: z.string(),
  operator: z.string(),
  value: z.unknown(),
  matched: z.boolean(),
  /** Why it matched or did not: matched | no_overlap | out_of_range | unknown_condition_type | … */
  reason: z.string(),
  /** Present for numeric conditions: the customer's actual figure. */
  actual: z.number().optional(),
});

export const conditionsPreviewSchema = z.object({
  campaign_id: z.number().int(),
  user_id: z.number().int(),
  matches: z.boolean(),
  conditions: z.array(conditionResultSchema),
});

export type ConditionResult = z.infer<typeof conditionResultSchema>;
export type ConditionsPreview = z.infer<typeof conditionsPreviewSchema>;

export async function previewConditions(campaignId: number, userId: number): Promise<ConditionsPreview> {
  const payload = await api.post(`campaigns/${campaignId}/preview-conditions`, { json: { user_id: userId } }).json();

  return conditionsPreviewSchema.parse(payload);
}
