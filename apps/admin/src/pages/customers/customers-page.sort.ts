import type { CustomerSortDirection, CustomerSortKey } from '@/lib/schema/customer.schema';

/** Sort shapes for the toolbar's carets; ordering runs server-side in CustomerRowSort.php. */
export type SortKey = CustomerSortKey;
export type SortDirection = CustomerSortDirection;

/** Text columns start A→Z; numeric and date columns start biggest/most recent first. */
export function defaultSortDirection(key: SortKey): SortDirection {
  return key === 'name' ? 'asc' : 'desc';
}
