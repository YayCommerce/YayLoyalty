/** The most rows one import may hold; Customers' bulk actions stop at the same size. */
export const MAX_IMPORT_ROWS = 50_000;

export interface ImportCsvRow {
  /** The row's line in the file, the header being 1, so errors point at what the merchant sees. */
  row: number;
  email: string;
  /** As written; the server decides whether it is a whole number. */
  points: string;
  note: string;
  /** The email appears on an earlier row too; only the first is imported. */
  duplicate: boolean;
}

export type ImportCsvError = 'empty' | 'missing_columns' | 'too_many_rows';

export type ImportCsvResult = { rows: ImportCsvRow[]; error: null } | { rows: []; error: ImportCsvError };

/** Splits RFC 4180 text into records: quoted fields may hold commas, doubled quotes and line breaks. */
function records(text: string): string[][] {
  const out: string[][] = [];
  let record: string[] = [];
  let field = '';
  let quoted = false;

  for (let i = 0; i < text.length; i++) {
    const char = text[i];

    if (quoted) {
      if (char === '"' && text[i + 1] === '"') {
        field += '"';
        i++;
      } else if (char === '"') {
        quoted = false;
      } else {
        field += char;
      }
    } else if (char === '"') {
      quoted = true;
    } else if (char === ',') {
      record.push(field);
      field = '';
    } else if (char === '\n' || char === '\r') {
      if (char === '\r' && text[i + 1] === '\n') i++;
      record.push(field);
      out.push(record);
      record = [];
      field = '';
    } else {
      field += char;
    }
  }

  if (field !== '' || record.length > 0) {
    record.push(field);
    out.push(record);
  }

  return out;
}

/** The CSV's `email`, `points` and optional `note` columns, in any order and case, as rows to preview. */
export function parseImportCsv(text: string): ImportCsvResult {
  const all = records(text.replace(/^\uFEFF/, ''));
  const header = (all[0] ?? []).map((name) => name.trim().toLowerCase());
  const emailAt = header.indexOf('email');
  const pointsAt = header.indexOf('points');
  const noteAt = header.indexOf('note');

  if (all.length === 0) return { rows: [], error: 'empty' };
  if (emailAt < 0 || pointsAt < 0) return { rows: [], error: 'missing_columns' };

  const seen = new Set<string>();
  const rows: ImportCsvRow[] = [];

  all.slice(1).forEach((fields, index) => {
    if (fields.every((value) => value.trim() === '')) return;

    const email = (fields[emailAt] ?? '').trim();
    const key = email.toLowerCase();

    rows.push({
      row: index + 2,
      email,
      points: (fields[pointsAt] ?? '').trim(),
      note: noteAt < 0 ? '' : (fields[noteAt] ?? '').trim(),
      duplicate: seen.has(key),
    });
    seen.add(key);
  });

  if (rows.length === 0) return { rows: [], error: 'empty' };
  if (rows.length > MAX_IMPORT_ROWS) return { rows: [], error: 'too_many_rows' };

  return { rows, error: null };
}

/**
 * The import's id: a 53-bit hash (cyrb53) of the file's text, never 0. The server keys every row on it,
 * so the same file uploaded again previews as already imported and applies nothing.
 */
export function importIdForFile(text: string): number {
  let h1 = 0xdeadbeef;
  let h2 = 0x41c6ce57;

  for (let i = 0; i < text.length; i++) {
    const code = text.charCodeAt(i);
    h1 = Math.imul(h1 ^ code, 2654435761);
    h2 = Math.imul(h2 ^ code, 1597334677);
  }

  h1 = Math.imul(h1 ^ (h1 >>> 16), 2246822507) ^ Math.imul(h2 ^ (h2 >>> 13), 3266489909);
  h2 = Math.imul(h2 ^ (h2 >>> 16), 2246822507) ^ Math.imul(h1 ^ (h1 >>> 13), 3266489909);

  return 4294967296 * (2097151 & h2) + (h1 >>> 0) || 1;
}
