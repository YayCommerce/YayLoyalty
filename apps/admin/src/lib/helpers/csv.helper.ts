import { dateI18n } from '@wordpress/date';

/** Quotes a field only when it needs it, doubling any embedded quotes — RFC 4180. */
export function csvField(value: string | number): string {
  const text = String(value);
  return /[",\r\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
}

/** A header line plus one line per row, every field through csvField(). */
export function toCsv(header: readonly string[], rows: readonly (readonly (string | number)[])[]): string {
  const lines = [header, ...rows].map((fields) => fields.map(csvField).join(','));

  // CRLF: RFC 4180's line ending, and what Excel expects.
  return lines.join('\r\n');
}

/** Saves `content` as `<prefix>-<YYYY-MM-DD>.csv`, today in the site time zone. */
export function downloadCsv(prefix: string, content: string): void {
  // UTF-8 BOM so Excel (still the most common opener) detects the encoding
  // instead of mis-rendering non-ASCII names.
  const blob = new Blob(['\uFEFF', content], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);

  const link = document.createElement('a');
  link.href = url;
  link.download = `${prefix}-${dateI18n('Y-m-d', new Date())}.csv`;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(url);
}
