import { z } from 'zod';

import { PaginatedResponse } from '@/lib/api/api.type';

/** The paginated envelope around an item schema, so no module redefines the shape locally. */
export function paginatedSchema<T extends z.ZodTypeAny>(item: T) {
  return z.object({
    currentPage: z.number().int(),
    totalPages: z.number().int(),
    totalItems: z.number().int(),
    perPage: z.number().int(),
    data: z.array(item),
  });
}

/** Parses a paginated response as PaginatedResponse<T>. */
export function parsePaginated<T extends z.ZodTypeAny>(item: T, payload: unknown): PaginatedResponse<z.infer<T>> {
  return paginatedSchema(item).parse(payload);
}

/** Mirrors AbstractRepository::MAX_PER_PAGE, the source of truth. The server's per_page cap, so a full walk takes the fewest requests. */
export const MAX_PER_PAGE = 100;

/** Ceiling on pages one walk fetches; beyond that, fail fast rather than hang the tab. */
export const MAX_PAGES = 500;

/**
 * Every row of a paginated route, page by page at MAX_PER_PAGE. Sequential, not
 * parallel: it runs from a click, and N concurrent list requests are worse for
 * the server. Throws `tooLarge(MAX_PAGES * MAX_PER_PAGE)` past MAX_PAGES pages.
 */
export async function fetchAllPages<T>(
  fetchPage: (page: number, perPage: number) => Promise<{ data: T[]; totalPages: number }>,
  tooLarge: (maxRows: number) => string,
): Promise<T[]> {
  const first = await fetchPage(1, MAX_PER_PAGE);

  if (first.totalPages > MAX_PAGES) {
    throw new Error(tooLarge(MAX_PAGES * MAX_PER_PAGE));
  }

  const rows = [...first.data];

  for (let page = 2; page <= first.totalPages; page++) {
    const next = await fetchPage(page, MAX_PER_PAGE);
    rows.push(...next.data);
  }

  return rows;
}
