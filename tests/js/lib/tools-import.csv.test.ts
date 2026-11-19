import { describe, expect, test, vi } from 'vitest';

import { MAX_MATCHING_ROWS } from '@/pages/customers/customers-page.matching-customers';
import { importIdForRows, parseImportCsv } from '@/pages/tools/tools-import.csv';

// The row cap comes from the Customers module, which imports the API client; the client reads `window` at import.
vi.mock('@/lib/api/api', () => ({ api: {}, isAbortError: () => false, toastApiError: () => undefined }));

describe('parseImportCsv @P-8', () => {
  test('reads email, points and note in any order and case, with a BOM and CRLF', () => {
    const result = parseImportCsv(
      '\uFEFFPoints,Note,EMAIL\r\n100,"Moved, from old store",ann@example.com\r\n-5,,bob@example.com\r\n',
    );

    expect(result.error).toBeNull();
    expect(result.rows).toEqual([
      { row: 2, email: 'ann@example.com', points: '100', note: 'Moved, from old store', duplicate: false },
      { row: 3, email: 'bob@example.com', points: '-5', note: '', duplicate: false },
    ]);
  });

  test('keeps doubled quotes and line breaks inside a quoted field', () => {
    const { rows } = parseImportCsv('email,points,note\nann@example.com,10,"Said ""hi""\nthen left"');

    expect(rows[0].note).toBe('Said "hi"\nthen left');
  });

  test('marks a repeated email, in any case, as a duplicate of the first', () => {
    const { rows } = parseImportCsv('email,points\nann@example.com,10\nANN@example.com,20');

    expect(rows.map((row) => row.duplicate)).toEqual([false, true]);
  });

  test('skips blank lines but keeps the file line numbers', () => {
    const { rows } = parseImportCsv('email,points\n\nann@example.com,10\n,\n');

    expect(rows).toHaveLength(1);
    expect(rows[0].row).toBe(3);
  });

  test('refuses a file without email and points columns, an empty file, and one too large', () => {
    expect(parseImportCsv('mail,amount\nann@example.com,10').error).toBe('missing_columns');
    expect(parseImportCsv('').error).toBe('empty');
    expect(parseImportCsv('email,points\n').error).toBe('empty');
    expect(parseImportCsv('email,points\n' + 'a@b.co,1\n'.repeat(MAX_MATCHING_ROWS + 1)).error).toBe('too_many_rows');
  });
});

describe('importIdForRows @P-8', () => {
  const idOf = (text: string) => importIdForRows(parseImportCsv(text).rows);

  test('is a positive safe integer that survives a spreadsheet re-save', () => {
    const id = idOf('email,points\nann@example.com,10');

    expect(id).toBeGreaterThan(0);
    expect(Number.isSafeInteger(id)).toBe(true);
    expect(idOf('\uFEFF"email","points"\r\n"Ann@example.com","10"\r\n')).toBe(id);
  });

  test('changes when a row changes', () => {
    expect(idOf('email,points\nann@example.com,11')).not.toBe(idOf('email,points\nann@example.com,10'));
  });
});
