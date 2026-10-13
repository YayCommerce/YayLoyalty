import { fetchAllPages } from '@/lib/api/paginated';
import { downloadCsv, toCsv } from '@/lib/helpers/csv.helper';
import type { StoreLedgerEntry } from '@/lib/schema/store-ledger.schema';
import { LEDGER_CSV_HEADERS } from '@/pages/customers/drawer/customer-drawer.copy';
import { ledgerCsvRow } from '@/pages/customers/drawer/customer-drawer.ledger-export';
import { getStoreLedger, type StoreLedgerParams } from './store-ledger.api';
import { CSV_CUSTOMER_HEADERS, exportTooLarge } from './store-ledger.copy';

/**
 * Every entry the page's filters match, oldest first.
 * Ascending by id, so an entry written mid-walk lands on the last page and shifts no row across pages.
 */
export function fetchFilteredLedger(
  filters: Omit<StoreLedgerParams, 'page' | 'perPage' | 'order'>,
  signal?: AbortSignal,
): Promise<StoreLedgerEntry[]> {
  return fetchAllPages(
    (page, perPage) => getStoreLedger({ ...filters, page, perPage, order: 'asc' }, signal),
    exportTooLarge,
  );
}

/** The drawer's ledger CSV columns, with who each entry belongs to in front. */
export function buildStoreLedgerCsv(entries: StoreLedgerEntry[], campaignNames: Map<number, string>): string {
  return toCsv(
    [...CSV_CUSTOMER_HEADERS(), ...LEDGER_CSV_HEADERS()],
    entries.map((entry) => [entry.customer_name, entry.customer_email, ...ledgerCsvRow(entry, campaignNames)]),
  );
}

export function exportStoreLedgerToCsv(entries: StoreLedgerEntry[], campaignNames: Map<number, string>): void {
  downloadCsv('ledger', buildStoreLedgerCsv(entries, campaignNames));
}
