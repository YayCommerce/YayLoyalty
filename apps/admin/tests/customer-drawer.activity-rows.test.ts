import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { getSettings, setSettings } from '@wordpress/date';

import { formatStoredDate } from '@/lib/helpers/format.helper';
import { toActivityRow } from '@/pages/customers/drawer/customer-drawer.activity-rows';

const USD = { symbol: '$', position: 'left', thousand_sep: ',', decimal_sep: '.', num_decimals: 2 };

beforeAll(() => {
  setSettings({
    ...getSettings(),
    timezone: { offset: 0, offsetFormatted: '+00:00', string: 'UTC', abbr: 'UTC' },
  });
});

beforeEach(() => {
  vi.stubGlobal('window', { yayLoyaltyMeta: { wcMeta: { currency_data: USD } } });
});

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('toActivityRow', () => {
  it("shows an expiry reminder's stored deadline, not occurred_at plus reminder_days", () => {
    const row = toActivityRow({
      id: 1,
      occurred_at: '2026-11-01 09:00:00',
      event_type: 'expiring_soon_email_sent',
      data: { points: 500, reminder_days: 7, expires_at: '2026-11-20 00:00:00' },
      actor_kind: 'automatic',
      actor_name: null,
      staff_only: false,
    });

    expect(row.subLine).toContain(formatStoredDate('2026-11-20 00:00:00'));
    expect(row.subLine).not.toContain(formatStoredDate('2026-11-08 09:00:00'));
  });
});
