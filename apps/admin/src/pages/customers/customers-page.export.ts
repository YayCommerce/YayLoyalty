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

/** Quotes a field only when it needs it, doubling any embedded quotes — RFC 4180. */
function csvField(value: string | number): string {
  const text = String(value);
  return /[",\r\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
}

/** Pure — no I/O — so it's cheap to unit test independently of the download step. */
export function buildCustomersCsv(rows: CustomerListRow[]): string {
  const lines = [CSV_HEADERS.join(',')];

  for (const row of rows) {
    lines.push(
      [
        row.user_id,
        row.name,
        row.email,
        row.balance,
        row.pending_points,
        row.lifetime_earned,
        row.last_activity_at ?? '',
        row.expires_at ?? '',
      ]
        .map(csvField)
        .join(','),
    );
  }

  // CRLF: RFC 4180's line ending, and what Excel expects.
  return lines.join('\r\n');
}

/** Real browser download — a `Blob` URL + a throwaway `<a download>`, no server round trip. */
function downloadCsv(filename: string, content: string): void {
  // UTF-8 BOM so Excel (still the most common opener) detects the encoding
  // instead of mis-rendering non-ASCII names.
  const blob = new Blob(['﻿' + content], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);

  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(url);
}

export function exportCustomersToCsv(rows: CustomerListRow[]): void {
  const today = new Date().toISOString().slice(0, 10);
  downloadCsv(`customers-${today}.csv`, buildCustomersCsv(rows));
}
