import { dateI18n } from '@wordpress/date';

import { getCustomerLedger } from '@/lib/api/customer.api';
import { fetchAllPages } from '@/lib/api/paginated';
import { downloadCsv, toCsv } from '@/lib/helpers/csv.helper';
import { utcToDate } from '@/lib/helpers/format.helper';
import { describeLedgerEntry, formatSignedPoints, ledgerTypeLabel } from '@/lib/helpers/ledger.helper';
import type { LedgerEntry } from '@/lib/schema/ledger.schema';
import { exportLedgerTooLarge, LEDGER_CSV_HEADERS } from './customer-drawer.copy';

/**
 * Every ledger entry of one customer, oldest first, ignoring the Ledger tab's filters.
 * Ascending by id, so an entry written mid-walk lands on the last page and shifts no row across pages.
 */
export function fetchFullLedger(userId: number, signal?: AbortSignal): Promise<LedgerEntry[]> {
  return fetchAllPages(
    (page, perPage) => getCustomerLedger(userId, { page, perPage, order: 'asc' }, signal),
    exportLedgerTooLarge,
  );
}

export function buildLedgerCsv(entries: LedgerEntry[], campaignNames: Map<number, string>): string {
  return toCsv(
    LEDGER_CSV_HEADERS(),
    entries.map((entry) => [
      entry.id,
      // dateI18n converts to the site time zone by default; the fixed format keeps the column sortable.
      dateI18n('Y-m-d H:i:s', utcToDate(entry.created_at)),
      ledgerTypeLabel(entry.type),
      describeLedgerEntry(entry),
      formatSignedPoints(entry.points),
      entry.balance_after,
      entry.order_id ?? '',
      campaignCell(entry.campaign_id, campaignNames),
      entry.note ?? '',
      entry.customer_note ?? '',
    ]),
  );
}

/** The campaign's name, or `#id` when the name is unknown (the map is empty without the campaigns capability). */
function campaignCell(campaignId: number | null, campaignNames: Map<number, string>): string {
  if (campaignId === null) return '';

  return campaignNames.get(campaignId) ?? `#${campaignId}`;
}

export function exportLedgerToCsv(userId: number, entries: LedgerEntry[], campaignNames: Map<number, string>): void {
  downloadCsv(`ledger-${userId}`, buildLedgerCsv(entries, campaignNames));
}
