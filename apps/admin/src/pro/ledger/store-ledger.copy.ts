import { __, sprintf } from '@wordpress/i18n';

import { formatStoreNumber } from '@/lib/helpers/format.helper';

export const PAGE_TITLE = () => __('Ledger', 'yayloyalty');
export const BACK_LABEL = () => __('Dashboard', 'yayloyalty');
export const LOAD_ERROR = () => __('Could not load the ledger.', 'yayloyalty');

/** "4,812 entries across every customer" */
export function pageDescription(total: number): string {
  return sprintf(
    /* translators: %s: total number of ledger entries in the store. */
    __('%s entries across every customer', 'yayloyalty'),
    formatStoreNumber(total, 0),
  );
}

export const SEARCH_PLACEHOLDER = () => __('Search customer, note or order number', 'yayloyalty');
export const CAMPAIGN_FILTER_LABEL = () => __('Campaign', 'yayloyalty');
export const CAMPAIGN_FILTER_ALL = () => __('All', 'yayloyalty');

export const COLUMN_CUSTOMER = () => __('Customer', 'yayloyalty');
export const COLUMN_EVENT = () => __('Event', 'yayloyalty');
export const COLUMN_BALANCE_AFTER = () => __('Balance after', 'yayloyalty');
export const BALANCE_AFTER_HINT = () => __("That customer's balance once this entry applied.", 'yayloyalty');

export const EMPTY_STORE = () =>
  __('No points activity yet. Entries appear here as customers earn and redeem.', 'yayloyalty');
export const EMPTY_FILTERED = () => __('No entries match these filters.', 'yayloyalty');

export const ACTION_EXPORT = () => __('Export CSV', 'yayloyalty');
export const EXPORT_EMPTY = () => __('No ledger entries to export.', 'yayloyalty');
export const EXPORT_ERROR_FALLBACK = () => __('Could not export the ledger.', 'yayloyalty');

export function exportTooLarge(max: number): string {
  return sprintf(
    /* translators: %d: the most ledger entries one export can hold. */
    __('These filters match more than %d entries — too many to export. Narrow the date range.', 'yayloyalty'),
    max,
  );
}

export const CSV_CUSTOMER_HEADERS = () => [__('Customer', 'yayloyalty'), __('Email', 'yayloyalty')];
