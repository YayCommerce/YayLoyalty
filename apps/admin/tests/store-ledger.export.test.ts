import { beforeAll, describe, expect, it, vi } from 'vitest';
import { getSettings, setSettings } from '@wordpress/date';

import { buildStoreLedgerCsv } from '@/pro/ledger/store-ledger.export';
import { storeLedgerEntry } from './fixtures/store-ledger.fixture';

// The API client reads `window` when it loads; the export never calls it here.
vi.mock('@/lib/api/api', () => ({ api: { get: vi.fn() } }));

beforeAll(() => {
  // Dates go out in the site time zone; pin it so the column is predictable.
  setSettings({
    ...getSettings(),
    timezone: { offset: 0, offsetFormatted: '+00:00', string: 'UTC', abbr: 'UTC' },
  });
});

/** The CSV as rows of cells. No test value contains a comma or quote, so a plain split is exact. */
function parse(csv: string): string[][] {
  return csv
    .split('\r\n')
    .filter(Boolean)
    .map((line) => line.split(','));
}

describe('buildStoreLedgerCsv', () => {
  it('puts the customer in front of the drawer ledger columns', () => {
    const [header] = parse(buildStoreLedgerCsv([], new Map()));

    expect(header).toEqual([
      'Customer',
      'Email',
      'Entry ID',
      'Date',
      'Type',
      'Description',
      'Points',
      'Balance after',
      'Order ID',
      'Campaign',
      'Note',
      'Customer description',
    ]);
  });

  it('writes one row per entry, cell for cell under its header', () => {
    const [header, row] = parse(buildStoreLedgerCsv([storeLedgerEntry()], new Map([[15, 'Spring promo']])));

    expect(row).toHaveLength(header.length);
    expect(row).toEqual([
      'Ada Lovelace',
      'ada@example.com',
      '54',
      '2026-10-12 02:28:53',
      'Earned',
      'Earned on order #751',
      '+20',
      '120',
      '751',
      'Spring promo',
      '',
      '',
    ]);
  });

  it('signs points both ways and keeps a negative balance', () => {
    const rows = parse(
      buildStoreLedgerCsv(
        [
          storeLedgerEntry({ id: 1, points: 30, balance_after: 30 }),
          storeLedgerEntry({ id: 2, type: 'adjust', source: 'manual', points: -45, balance_after: -15, note: 'Fix' }),
        ],
        new Map(),
      ),
    ).slice(1);

    expect(rows.map((row) => [row[6], row[7]])).toEqual([
      ['+30', '30'],
      ['-45', '-15'],
    ]);
  });

  it('names an unknown campaign by its id, and leaves a row without one blank', () => {
    const rows = parse(
      buildStoreLedgerCsv(
        [storeLedgerEntry({ id: 1, campaign_id: 99 }), storeLedgerEntry({ id: 2, campaign_id: null })],
        new Map(),
      ),
    ).slice(1);

    expect(rows.map((row) => row[9])).toEqual(['#99', '']);
  });
});
