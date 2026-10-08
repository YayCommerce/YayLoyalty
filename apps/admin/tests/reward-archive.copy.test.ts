import { describe, expect, it } from 'vitest';

import { resolveRewardArchiveCase } from '@/pro/rewards/archive/reward-archive.copy';
import { reward } from './fixtures/reward.fixture';

describe('resolveRewardArchiveCase', () => {
  it('archives whatever the server will not delete', () => {
    expect(resolveRewardArchiveCase(reward({ is_deletable: false }))).toBe('archive');
    expect(resolveRewardArchiveCase(reward({ is_deletable: false, status: 'archived' }))).toBe('archive');
  });

  it('offers archive instead only while the reward is not archived yet', () => {
    expect(resolveRewardArchiveCase(reward({ is_deletable: true }))).toBe('delete');
    expect(resolveRewardArchiveCase(reward({ is_deletable: true, status: 'archived' }))).toBe('delete-only');
  });
});
