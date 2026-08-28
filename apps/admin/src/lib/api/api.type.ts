export interface PaginatedResponse<T> {
  currentPage: number;
  totalPage: number;
  totalItems: number;
  data: T[];
}
