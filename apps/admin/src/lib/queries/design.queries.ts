import { queryOptions, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { getDesign, postDesign, resetDesign } from '@/lib/api/design.api';
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
