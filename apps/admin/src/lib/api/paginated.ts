import { z } from 'zod';

import { PaginatedResponse } from '@/lib/api/api.type';

/**
 * Build a zod schema for the paginated envelope around a given item schema.
 *
 * Exists so every collection endpoint validates the envelope the same way and
 * no module redefines the pagination shape locally.
 */
export function paginatedSchema<T extends z.ZodTypeAny>(item: T) {
  return z.object({
    currentPage: z.number().int(),
    totalPage: z.number().int(),
    totalItems: z.number().int(),
    perPage: z.number().int(),
    data: z.array(item),
  });
}

/**
 * Parse a paginated response, keeping the shared PaginatedResponse<T> type.
 */
export function parsePaginated<T extends z.ZodTypeAny>(
  item: T,
  payload: unknown,
): PaginatedResponse<z.infer<T>> {
  return paginatedSchema(item).parse(payload);
}
