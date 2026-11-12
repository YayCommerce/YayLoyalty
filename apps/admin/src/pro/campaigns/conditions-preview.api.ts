import { queryOptions, useQuery } from '@tanstack/react-query';
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

export const conditionsPreviewSchema = z.object({
  campaign_id: z.number().int(),
  user_id: z.number().int(),
  matches: z.boolean(),
  conditions: z.array(conditionResultSchema),
  /** The storefront teaser the conditions word to in Auto mode; null when they can't all be worded. */
  phrase: z.string().nullable(),
});

export type ConditionResult = z.infer<typeof conditionResultSchema>;
export type ConditionsPreview = z.infer<typeof conditionsPreviewSchema>;

/** Without `userId` the conditions are explained for no one, which is enough for `phrase`. */
export async function previewConditions(campaignId: number, userId?: number): Promise<ConditionsPreview> {
  const json = userId === undefined ? {} : { user_id: userId };
  const payload = await api.post(`campaigns/${campaignId}/preview-conditions`, { json }).json();

  return conditionsPreviewSchema.parse(payload);
}

/**
 * Keyed under `['campaign', 'detail', id, ...]`, so the campaign write mutations, which invalidate
 * the detail, refetch the phrase after a save: it is worded from the saved conditions.
 */
export const CONDITIONS_PREVIEW_QUERIES = {
  phrase: (campaignId: number) =>
    queryOptions({
      queryKey: ['campaign', 'detail', campaignId, 'phrase'],
      queryFn: async () => (await previewConditions(campaignId)).phrase,
      retry: 1,
    }),
};

/** The Auto teaser of a saved campaign's conditions; off while `enabled` is false (a campaign not saved yet). */
export function useConditionsPhraseQuery(campaignId: number, enabled: boolean) {
  return useQuery({ ...CONDITIONS_PREVIEW_QUERIES.phrase(campaignId), enabled });
}
