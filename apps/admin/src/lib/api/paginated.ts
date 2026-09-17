import { z } from 'zod';

import { PaginatedResponse } from '@/lib/api/api.type';

/** The paginated envelope around an item schema, so no module redefines the shape locally. */
export function paginatedSchema<T extends z.ZodTypeAny>(item: T) {
  return z.object({
    currentPage: z.number().int(),
    totalPage: z.number().int(),
    totalItems: z.number().int(),
    perPage: z.number().int(),
    data: z.array(item),
  });
}

/** Parses a paginated response as PaginatedResponse<T>. */
export function parsePaginated<T extends z.ZodTypeAny>(item: T, payload: unknown): PaginatedResponse<z.infer<T>> {
  return paginatedSchema(item).parse(payload);
}
