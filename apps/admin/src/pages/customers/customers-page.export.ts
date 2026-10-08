import { downloadCsv, toCsv } from '@/lib/helpers/csv.helper';
import type { CustomerListRow } from '@/lib/schema/customer.schema';

const CSV_HEADERS = [
  'User ID',
  'Name',
  'Email',
  'Balance',
  'Pending points',
  'Lifetime earned',
  'Last activity',
  'Expires',
] as const;

export function buildCustomersCsv(rows: CustomerListRow[]): string {
  return toCsv(
    CSV_HEADERS,
    rows.map((row) => [
      row.user_id,
      row.name,
      row.email,
      row.balance,
      row.pending_points,
      row.lifetime_earned,
      row.last_activity_at ?? '',
      row.expires_at ?? '',
    ]),
  );
}

export function exportCustomersToCsv(rows: CustomerListRow[]): void {
  downloadCsv('customers', buildCustomersCsv(rows));
}
