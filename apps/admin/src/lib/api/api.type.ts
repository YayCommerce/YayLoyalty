/** Envelope for every paginated endpoint. camelCase, while the entities inside `data` stay snake_case to mirror PHP. */
export interface PaginatedResponse<T> {
  currentPage: number;
  totalPages: number;
  totalItems: number;
  /** Page size the server actually applied, which may be lower than requested. */
  perPage: number;
  data: T[];
}
