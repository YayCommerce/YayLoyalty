import { describe, expect, it, vi } from 'vitest';

import { normalizeRewardConditions, Reward, RewardCondition } from '@/lib/schema/reward.schema';
import {
  duplicateToFormValues,
  formValuesToCreateInput,
  formValuesToUpdateInput,
  resolveSaveStatus,
  rewardCreateSeed,
  rewardEditorActions,
  rewardFormSchema,
  rewardToFormValues,
} from '@/pro/rewards/editor/reward-editor.form';
import { reward } from './fixtures/reward.fixture';

// A new reward's default name is money-formatted, and some seeds are built while the suite is collected.
vi.stubGlobal('window', {
  yayLoyaltyMeta: {
    wcMeta: { currency_data: { symbol: '$', position: 'left', thousand_sep: ',', decimal_sep: '.', num_decimals: 2 } },
  },
});

describe('resolveSaveStatus', () => {
  it.each([
    ['active', 'save', 'active'],
    ['paused', 'save', 'paused'],
    ['draft', 'save', 'draft'],
    ['active', 'draft', 'draft'],
    ['paused', 'draft', 'draft'],
  ] as const)('%s + %s → %s', (status, intent, expected) => {
    expect(resolveSaveStatus({ ...rewardCreateSeed('fixed_coupon', 'USD'), status }, intent)).toBe(expected);
  });

  it('seeds a new reward as active and loads an archived one as active', () => {
    expect(rewardCreateSeed('fixed_coupon', 'USD').status).toBe('active');
    expect(rewardToFormValues(reward({ status: 'archived' }), 'USD').status).toBe('active');
    expect(rewardToFormValues(reward({ status: 'paused' }), 'USD').status).toBe('paused');
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

  it('hides Archive when new, archived or the built-in', () => {
    expect(rewardEditorActions(null).showArchive).toBe(false);
    expect(rewardEditorActions(reward({ status: 'archived' })).showArchive).toBe(false);
    expect(rewardEditorActions(reward({ status: 'active' })).showArchive).toBe(true);
    expect(rewardEditorActions(reward({ is_builtin: true })).showArchive).toBe(false);
  });
});

describe('normalizeRewardConditions', () => {
  const role: RewardCondition = { type: 'user_role', operator: 'in', value: ['customer'] };

  it('drops malformed entries and a non-list envelope', () => {
    expect(normalizeRewardConditions({})).toEqual([]);
    expect(normalizeRewardConditions([null, 'x', { operator: 'lt' }, role] as unknown as Reward['conditions'])).toEqual(
      [role],
    );
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

  it('fills keys a stored row lacks', () => {
    const values = rewardToFormValues(reward({ config: { amount: 7 } }), 'EUR');

    expect(values.config.amount).toBe(7);
    expect(values.config.currency).toBe('EUR');
    expect(values.config.min_order).toBe(25);
  });

  it('builds create and update bodies with the resolved status and the stored conditions', () => {
    const seed = rewardCreateSeed('fixed_coupon', 'USD');
    const create = formValuesToCreateInput(seed, 'fixed_coupon', 'draft');

    expect(create.type).toBe('fixed_coupon');
    expect(create.status).toBe('draft');
    expect(create.conditions).toEqual([]);

    const stored = reward({ status: 'paused', conditions: [{ type: 'lifetime_points', operator: 'gte', value: 5 }] });
    const update = formValuesToUpdateInput({ ...seed, status: 'paused' }, stored, 'save');

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
      max_percent_of_discountable: null,
      increment: 100,
      show_on: ['cart', 'checkout'],
      refund_policy: 'proportional',
      max_total: null,
    };
    const values = {
      name: 'Points',
      status: 'active',
      config,
      starts_at: null,
      ends_at: null,
    };

    expect(rewardFormSchema('flexible_discount').safeParse({ ...values, cost_points: null }).success).toBe(true);
    expect(rewardFormSchema('flexible_discount').safeParse({ ...values, cost_points: 100 }).success).toBe(false);
  });
});

describe('duplicateToFormValues', () => {
  it('copies the source and marks the name as a copy', () => {
    const source = reward({
      name: '$5 off',
      cost_points: 700,
      config: { amount: 5 },
      starts_at: '2026-11-01 00:00:00',
    });

    const values = duplicateToFormValues(source, 'USD');

    expect(values.name).toBe('$5 off (copy)');
    expect(values.cost_points).toBe(700);
    expect(values.config.amount).toBe(5);
    expect(values.starts_at).toBe('2026-11-01 00:00:00');
  });
});

describe('formValuesToCreateInput with source conditions', () => {
  it('keeps rules the form cannot show when a reward is duplicated', () => {
    const role: RewardCondition = { type: 'user_role', operator: 'in', value: ['customer'] };
    const values = duplicateToFormValues(reward({ conditions: [role] }), 'USD');

    expect(formValuesToCreateInput(values, 'fixed_coupon', 'save', [role]).conditions).toEqual([role]);
  });
});
