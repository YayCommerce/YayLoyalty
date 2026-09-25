import { afterEach, describe, expect, it } from 'vitest';

import { parseWPDate, parseWPTime } from '@/lib/helpers/format.helper';

/**
 * A stored UTC datetime handed to the formatters as a string must be read as
 * UTC. dateI18n reads a bare string as browser-local, which moved a midnight
 * instant to the previous day for any viewer east of the site zone.
 */
const originalTz = process.env.TZ;

afterEach(() => {
  process.env.TZ = originalTz;
});

describe('parseWPDate / parseWPTime on a stored UTC string', () => {
  it('keeps a UTC midnight on its own day for a UTC+7 viewer', () => {
    process.env.TZ = 'Asia/Saigon';

    expect(parseWPDate('2026-09-27 00:00:00')).toBe('September 27, 2026');
    expect(parseWPTime('2026-09-27 00:00:00')).toBe('12:00 am');
  });

  it('keeps a UTC evening on its own day for a UTC-8 viewer', () => {
    process.env.TZ = 'America/Los_Angeles';

    expect(parseWPDate('2026-09-27 23:30:00')).toBe('September 27, 2026');
  });
});
