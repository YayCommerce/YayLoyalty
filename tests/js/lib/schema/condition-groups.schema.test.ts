import { describe, expect, test } from 'vitest';

import { hasConditions, toConditionTree } from '@/lib/schema/condition-groups.schema';
import { conditionsLabel } from '@/pro/campaigns/campaign-labels';

const country = { type: 'country', operator: 'in', value: { countries: ['US'], source: 'billing' } };
const spend = { type: 'lifetime_spend', operator: 'gte', value: { amount: 500, currency: 'USD' } };
const orders = { type: 'order_count', operator: 'gte', value: 5 };

const acceptance = {
  match: 'all',
  groups: [
    { match: 'all', conditions: [country] },
    { match: 'any', conditions: [spend, orders] },
  ],
};

describe('condition-groups.schema @P-1', () => {
  test('reads a flat list as one all group', () => {
    expect(toConditionTree([country, orders])).toEqual({
      match: 'all',
      groups: [{ match: 'all', conditions: [country, orders] }],
    });
    expect(toConditionTree([])).toEqual({ match: 'all', groups: [] });
  });

  test('leaves a tree unchanged', () => {
    expect(toConditionTree(acceptance)).toEqual(acceptance);
  });

  test('degrades a malformed payload to fewer rules', () => {
    expect(toConditionTree({ schema_version: 1 })).toEqual({ match: 'all', groups: [] });
    expect(toConditionTree([country, 'junk', { operator: 'in' }])).toEqual(toConditionTree([country]));
    expect(hasConditions({ match: 'any', groups: [{ match: 'all', conditions: [] }] })).toBe(false);
  });

  test('labels groups, and keeps the flat label for one all group', () => {
    expect(conditionsLabel(acceptance)).toBe('Country AND (Lifetime spend OR Order count)');
    expect(conditionsLabel([country, orders])).toBe('Country, Order count');
    expect(conditionsLabel({ match: 'all', groups: [{ match: 'any', conditions: [spend, orders] }] })).toBe(
      'Lifetime spend OR Order count',
    );
    expect(conditionsLabel([])).toBe('No conditions');
  });
});
