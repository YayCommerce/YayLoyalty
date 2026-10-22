import type { QueryClient, QueryKey } from '@tanstack/react-query';

import type { PaginatedResponse } from '@/lib/api/api.type';

/** Writes a saved row over its copy in every cached page under `listKey`, so a one-row write needs no list refetch. */
export function setPaginatedRow<T extends { id: number }>(queryClient: QueryClient, listKey: QueryKey, row: T) {
  queryClient.setQueriesData<PaginatedResponse<T>>({ queryKey: listKey }, (data) =>
    data ? { ...data, data: data.data.map((item) => (item.id === row.id ? row : item)) } : data,
  );
}
