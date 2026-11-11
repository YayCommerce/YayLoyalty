import { queryOptions, useQuery } from '@tanstack/react-query';

import { previewConditions } from './conditions-preview.api';

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
