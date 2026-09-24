import { describe, expect, it } from 'vitest';

import { settleInChunks } from '@/lib/helpers/settle-in-chunks.helper';

/**
 * `settleInChunks()` backs both bulk writes (customers adjust, campaigns
 * archive): a failure must not abort the rest, and no more than one chunk may
 * be in flight at once.
 */
describe('settleInChunks', () => {
  it('keeps going past a failure and returns the failed item', async () => {
    const items = Array.from({ length: 25 }, (_, i) => i);

    const { succeeded, failedItems } = await settleInChunks(items, async (item) => {
      if (item === 13) throw new Error('boom');
    });

    expect(succeeded).toBe(24);
    expect(failedItems).toEqual([13]);
  });

  it('never runs more than one chunk at once', async () => {
    let inFlight = 0;
    let peak = 0;

    await settleInChunks(
      Array.from({ length: 25 }, (_, i) => i),
      async () => {
        inFlight++;
        peak = Math.max(peak, inFlight);
        await Promise.resolve();
        inFlight--;
      },
    );

    expect(peak).toBe(10);
  });

  it('settles an empty list without calling the task', async () => {
    expect(await settleInChunks([], async () => {})).toEqual({ succeeded: 0, failedItems: [] });
  });
});
