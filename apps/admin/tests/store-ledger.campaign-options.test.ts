import { describe, expect, it } from 'vitest';

import { campaignFilterOptions } from '@/pro/ledger/store-ledger.campaign-options';

describe('campaignFilterOptions', () => {
  it('adds the id only to names several campaigns share', () => {
    const options = campaignFilterOptions(
      new Map([
        [13, 'asdasd'],
        [2, 'Points for purchases'],
        [6, 'asdasd'],
      ]),
    );

    expect(options.map((option) => option.label)).toEqual(['asdasd (#6)', 'asdasd (#13)', 'Points for purchases']);
  });

  it('sorts by name, then by id within a shared name', () => {
    const options = campaignFilterOptions(
      new Map([
        [8, 'Spring'],
        [3, 'Autumn'],
        [5, 'Spring'],
      ]),
    );

    expect(options.map((option) => option.id)).toEqual([3, 5, 8]);
  });

  it('is empty when no campaign names loaded', () => {
    expect(campaignFilterOptions(new Map())).toEqual([]);
  });
});
