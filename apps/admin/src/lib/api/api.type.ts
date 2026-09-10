/**
 * Envelope for every paginated YayLoyalty endpoint.
 *
 * Casing here is deliberately mixed and should stay that way: the ENVELOPE is
 * camelCase, while the ENTITIES inside `data` are snake_case because they mirror
 * each resource's PHP `get_item_schema()` field-for-field. Renaming either side
 * would make the two halves drift.
 */
export interface PaginatedResponse<T> {
  currentPage: number;
  totalPage: number;
  totalItems: number;
  /** Page size the server actually applied, which may be lower than requested. */
  perPage: number;
  data: T[];
}
