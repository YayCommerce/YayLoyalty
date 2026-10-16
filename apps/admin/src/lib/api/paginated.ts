import * as v from 'valibot';

import { PaginatedResponse } from '@/lib/api/api.type';

/** The paginated envelope around an item schema, so no module redefines the shape locally. */
export function paginatedSchema<T extends v.GenericSchema>(item: T) {
  return v.object({
    currentPage: v.pipe(v.number(), v.integer()),
    totalPage: v.pipe(v.number(), v.integer()),
    totalItems: v.pipe(v.number(), v.integer()),
    perPage: v.pipe(v.number(), v.integer()),
    data: v.array(item),
  });
}

/** Parses a paginated response as PaginatedResponse<T>. */
export function parsePaginated<T extends v.GenericSchema>(
  item: T,
  payload: unknown,
): PaginatedResponse<v.InferOutput<T>> {
  return v.parse(paginatedSchema(item), payload);
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
  fetchPage: (page: number, perPage: number) => Promise<{ data: T[]; totalPage: number }>,
  tooLarge: (maxRows: number) => string,
): Promise<T[]> {
  const first = await fetchPage(1, MAX_PER_PAGE);

  if (first.totalPage > MAX_PAGES) {
    throw new Error(tooLarge(MAX_PAGES * MAX_PER_PAGE));
  }

  const rows = [...first.data];

  for (let page = 2; page <= first.totalPage; page++) {
    const next = await fetchPage(page, MAX_PER_PAGE);
    rows.push(...next.data);
  }

  return rows;
}
