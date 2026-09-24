import { keepPreviousData, queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { getDesign, getPublishedPagesByIds, postDesign, resetDesign, searchPublishedPages } from '@/lib/api/design.api';
import type { Design } from '@/lib/schema/design.schema';

export const DESIGN_QUERIES = {
  main: queryOptions({
    queryKey: ['design', 'main'],
    queryFn: async () => {
      const design = await getDesign();
      window.yayLoyaltyAdmin.design = design;
      return design;
    },
    initialData: () => window.yayLoyaltyAdmin.design,
    staleTime: Infinity,
    // POST is a whole blob, not a patch; a tab left open would otherwise clobber a
    // second admin's save without either noticing. This narrows, not closes, that window.
    refetchOnWindowFocus: true,
  }),
};

/** Published pages matching `search` for the "Only the pages I choose" picker; fetched only while that scope is picked. */
export function usePublishedPagesSearchQuery(search: string, enabled: boolean) {
  return useQuery({
    queryKey: ['wp', 'pages', 'search', search],
    queryFn: () => searchPublishedPages(search),
    enabled,
    staleTime: Infinity,
    // Keeps the last results on screen while the next search loads, so the list doesn't flash empty.
    placeholderData: keepPreviousData,
  });
}

/** The picker's chosen pages; keyed by the sorted ids so reordering never refetches. */
export function usePublishedPagesByIdsQuery(ids: number[], enabled: boolean) {
  const sorted = [...ids].sort((a, b) => a - b);
  return useQuery({
    queryKey: ['wp', 'pages', 'ids', sorted],
    queryFn: () => getPublishedPagesByIds(sorted),
    enabled: enabled && sorted.length > 0,
    staleTime: Infinity,
    placeholderData: keepPreviousData,
  });
}

export function useDesignQuery() {
  return useQuery(DESIGN_QUERIES.main);
}

export function useSaveDesignMutation() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationKey: ['design', 'save'],
    mutationFn: (data: Design) => postDesign(data),
    onSuccess: (design) => {
      queryClient.setQueryData(DESIGN_QUERIES.main.queryKey, design);
    },
  });
}

export function useResetDesignMutation() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationKey: ['design', 'reset'],
    mutationFn: (group?: string) => resetDesign(group),
    onSuccess: (design) => {
      queryClient.setQueryData(DESIGN_QUERIES.main.queryKey, design);
    },
  });
}
