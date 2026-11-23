import { describe, expect, test } from 'vitest';

import { hasConditions, toConditionList } from '@/lib/schema/condition-match.schema';
import { conditionsLabel } from '@/pro/campaigns/campaign-labels';

const country = { type: 'country', operator: 'in', value: { countries: ['US'], source: 'billing' } };
const orders = { type: 'order_count', operator: 'gte', value: 5 };

describe('condition-match.schema @P-1', () => {
  test('reads a flat list as all', () => {
    expect(toConditionList([country, orders])).toEqual({ match: 'all', conditions: [country, orders] });
    expect(toConditionList([])).toEqual({ match: 'all', conditions: [] });
  });

  test('keeps an any list', () => {
    expect(toConditionList({ match: 'any', conditions: [country, orders] })).toEqual({
      match: 'any',
      conditions: [country, orders],
    });
  });

  test('degrades a malformed payload to fewer rules', () => {
    expect(toConditionList({ schema_version: 1 })).toEqual({ match: 'all', conditions: [] });
    expect(toConditionList({ match: 'xor', conditions: [country] })).toEqual({ match: 'all', conditions: [country] });
    expect(toConditionList([country, 'junk', { operator: 'in' }])).toEqual(toConditionList([country]));
    expect(hasConditions({ match: 'any', conditions: [] })).toBe(false);
  });

  test('labels an all list with commas and an any list with OR', () => {
    expect(conditionsLabel([country, orders])).toBe('Country, Order count');
    expect(conditionsLabel({ match: 'all', conditions: [country, orders] })).toBe('Country, Order count');
    expect(conditionsLabel({ match: 'any', conditions: [country, orders] })).toBe('Country OR Order count');
    expect(conditionsLabel([])).toBe('No conditions');
  });
});
