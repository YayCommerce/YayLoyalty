import { describe, expect, it } from 'vitest';

import { claimsPerCustomer, normalizeRewardConditions, Reward, RewardCondition } from '@/lib/schema/reward.schema';
import {
  formValuesToCreateInput,
  formValuesToUpdateInput,
  resolveSaveStatus,
  rewardCreateSeed,
  rewardEditorActions,
  rewardFormSchema,
  rewardToFormValues,
  withClaimsPerCustomer,
} from '@/pro/rewards/editor/reward-editor.form';
import { reward } from './fixtures/reward.fixture';

describe('resolveSaveStatus', () => {
  it.each([
    ['new', null, 'save', 'active'],
    ['new', null, 'draft', 'draft'],
    ['draft', 'draft', 'save', 'active'],
    ['draft', 'draft', 'draft', 'draft'],
    ['active', 'active', 'save', 'active'],
    ['paused', 'paused', 'save', 'paused'],
    ['archived', 'archived', 'save', 'active'],
  ] as const)('%s + %s → %s', (_label, status, intent, expected) => {
    expect(resolveSaveStatus(status === null ? null : reward({ status }), intent)).toBe(expected);
  });

  it('keeps the built-in status whatever was pressed', () => {
    const builtin = reward({ type: 'flexible_discount', is_builtin: true, status: 'paused' });

    expect(resolveSaveStatus(builtin, 'save')).toBe('paused');
    expect(resolveSaveStatus(builtin, 'draft')).toBe('paused');
  });
});

describe('rewardEditorActions', () => {
  it('offers Save draft only while new or a non-built-in draft', () => {
    expect(rewardEditorActions(null).canSaveDraft).toBe(true);
    expect(rewardEditorActions(reward({ status: 'draft' })).canSaveDraft).toBe(true);
    expect(rewardEditorActions(reward({ status: 'active' })).canSaveDraft).toBe(false);
    expect(rewardEditorActions(reward({ status: 'paused' })).canSaveDraft).toBe(false);
    expect(rewardEditorActions(reward({ status: 'draft', is_builtin: true })).canSaveDraft).toBe(false);
  });

  it('hides Archive when new or archived and disables it on the built-in', () => {
    expect(rewardEditorActions(null).showArchive).toBe(false);
    expect(rewardEditorActions(reward({ status: 'archived' })).showArchive).toBe(false);
    expect(rewardEditorActions(reward({ status: 'active' })).showArchive).toBe(true);
    expect(rewardEditorActions(reward({ is_builtin: true })).isArchiveDisabled).toBe(true);
  });
});

describe('claims per customer', () => {
  const role: RewardCondition = { type: 'user_role', operator: 'in', value: ['customer'] };

  it('reads lt as-is and lte as one more', () => {
    expect(claimsPerCustomer([{ type: 'redemption_count', operator: 'lt', value: 1 }])).toBe(1);
    expect(claimsPerCustomer([{ type: 'redemption_count', operator: 'lte', value: 2 }])).toBe(3);
    expect(claimsPerCustomer([{ type: 'redemption_count', operator: 'eq', value: 0 }])).toBeNull();
    expect(claimsPerCustomer([role])).toBeNull();
  });

  it('drops malformed entries and a non-list envelope', () => {
    expect(normalizeRewardConditions({})).toEqual([]);
    expect(normalizeRewardConditions([null, 'x', { operator: 'lt' }, role] as unknown as Reward['conditions'])).toEqual(
      [role],
    );
  });

  it('sets, replaces and removes only the redemption_count rule', () => {
    expect(withClaimsPerCustomer([role], 2)).toEqual([role, { type: 'redemption_count', operator: 'lt', value: 2 }]);
    expect(withClaimsPerCustomer([role, { type: 'redemption_count', operator: 'lt', value: 1 }], null)).toEqual([role]);
  });

  it('returns a rule it cannot show untouched when the input did not change', () => {
    const conditions = [role, { type: 'redemption_count', operator: 'eq', value: 0 }];

    expect(withClaimsPerCustomer(conditions, null)).toBe(conditions);
  });
});

describe('form values', () => {
  it('seeds each catalog type with a valid config', () => {
    for (const type of ['fixed_coupon', 'percent_coupon', 'free_shipping'] as const) {
      const seed = rewardCreateSeed(type, 'USD');

      expect(rewardFormSchema(type).safeParse(seed).success, type).toBe(true);
    }
  });

  it('requires a product before a free-product seed can save', () => {
    const seed = rewardCreateSeed('free_product', 'USD');

    expect(rewardFormSchema('free_product').safeParse(seed).success).toBe(false);
    expect(
      rewardFormSchema('free_product').safeParse({ ...seed, config: { ...seed.config, product_id: 12 } }).success,
    ).toBe(true);
  });

  it('fills keys a stored row lacks and keeps its claim cap', () => {
    const values = rewardToFormValues(
      reward({ config: { amount: 7 }, conditions: [{ type: 'redemption_count', operator: 'lt', value: 1 }] }),
      'EUR',
    );

    expect(values.config.amount).toBe(7);
    expect(values.config.currency).toBe('EUR');
    expect(values.config.coupon_expiry_days).toBe(60);
    expect(values.claims_per_customer).toBe(1);
  });

  it('builds create and update bodies with the resolved status and merged conditions', () => {
    const seed = { ...rewardCreateSeed('fixed_coupon', 'USD'), claims_per_customer: 1 };
    const create = formValuesToCreateInput(seed, 'fixed_coupon', 'draft');

    expect(create.type).toBe('fixed_coupon');
    expect(create.status).toBe('draft');
    expect(create.conditions).toEqual([{ type: 'redemption_count', operator: 'lt', value: 1 }]);

    const stored = reward({ status: 'paused', conditions: [{ type: 'lifetime_points', operator: 'gte', value: 5 }] });
    const update = formValuesToUpdateInput({ ...seed, claims_per_customer: null }, stored, 'save');

    expect(update.status).toBe('paused');
    expect(update.conditions).toEqual([{ type: 'lifetime_points', operator: 'gte', value: 5 }]);
  });
});

describe('rewardFormSchema bounds mirror the PHP specs', () => {
  const base = rewardCreateSeed('percent_coupon', 'USD');

  it.each([
    ['percent above 100', { config: { ...base.config, percent: 150 } }],
    ['percent below 1', { config: { ...base.config, percent: 0 } }],
    ['negative minimum order', { config: { ...base.config, min_order: -1 } }],
    ['zero expiry', { config: { ...base.config, coupon_expiry_days: 0 } }],
    ['fractional claim limit', { config: { ...base.config, max_total: 2.5 } }],
    ['zero cost', { cost_points: 0 }],
    ['missing cost', { cost_points: null }],
    ['blank name', { name: '  ' }],
    ['end before start', { starts_at: '2026-12-01 00:00:00', ends_at: '2026-11-01 00:00:00' }],
  ])('rejects %s', (_label, patch) => {
    expect(rewardFormSchema('percent_coupon').safeParse({ ...base, ...patch }).success).toBe(false);
  });

  it('requires a null cost on flexible_discount', () => {
    const config = {
      currency: 'USD',
      points_per_currency_unit: 100,
      min_points: 100,
      max_points_per_order: null,
      max_percent_of_subtotal: null,
      increment: 100,
      show_on: ['cart', 'checkout'],
      refund_policy: 'proportional',
      max_total: null,
    };
    const values = { name: 'Points', config, claims_per_customer: null, starts_at: null, ends_at: null };

    expect(rewardFormSchema('flexible_discount').safeParse({ ...values, cost_points: null }).success).toBe(true);
    expect(rewardFormSchema('flexible_discount').safeParse({ ...values, cost_points: 100 }).success).toBe(false);
  });
});
