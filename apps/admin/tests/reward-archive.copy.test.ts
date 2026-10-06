import { describe, expect, it } from 'vitest';

import type { RewardRedemptionSummary } from '@/lib/schema/reward.schema';
import {
  contextLine,
  keepsLines,
  outstandingWarning,
  resolveRewardArchiveCase,
} from '@/pro/rewards/archive/reward-archive.copy';
import { reward } from './fixtures/reward.fixture';

const CLAIMED: RewardRedemptionSummary = {
  claimed: 142,
  outstanding: 9,
  outstanding_customers: 8,
  outstanding_points: 4500,
  first_claimed_at: '2026-03-01 00:00:00',
  last_expires_at: '2026-11-04 12:00:00',
};

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

describe('contextLine', () => {
  it('states only the cost while nothing has been claimed', () => {
    expect(contextLine(reward({ cost_points: 500 }), 'archive')).toBe('500 points each');
  });

  it('leads with the claim count once claims exist', () => {
    const line = contextLine(reward({ cost_points: 500, redemption_summary: CLAIMED }), 'archive');

    expect(line.startsWith('142 claimed since ')).toBe(true);
    expect(line.endsWith(' · 500 points each')).toBe(true);
  });

  it('says never claimed in the delete case', () => {
    expect(contextLine(reward(), 'delete')).toContain('never claimed');
  });
});

describe('outstandingWarning', () => {
  it('is absent when no coupon is outstanding, so the card does not render', () => {
    expect(outstandingWarning(reward().redemption_summary)).toBeUndefined();
  });

  it('names customers, points spent and the last expiry', () => {
    const warning = outstandingWarning(CLAIMED);

    expect(warning?.headline).toBe('8 customers hold unused coupons from this reward');
    expect(warning?.detail).toContain('4,500 points');
    expect(warning?.detail).toContain('the last one on');
  });

  it('drops the expiry date when none is known', () => {
    expect(outstandingWarning({ ...CLAIMED, last_expires_at: null })?.detail).not.toContain('the last one on');
  });
});

describe('keepsLines', () => {
  it('is only the history line with no claims', () => {
    expect(keepsLines(reward().redemption_summary)).toEqual(['Customer history, still naming this reward']);
  });

  it('counts unused coupons and recorded redemptions separately', () => {
    expect(keepsLines(CLAIMED)).toEqual([
      'The 9 unused coupons, until they expire',
      '133 redemptions already recorded',
      'Customer history, still naming this reward',
    ]);
  });
});
