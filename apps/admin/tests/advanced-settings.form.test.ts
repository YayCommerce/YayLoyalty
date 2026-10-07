import { describe, expect, it } from 'vitest';

import {
  advancedSettingsFormSchema,
  AdvancedSettingsFormValues,
  buildAdvancedSettingsUpdate,
} from '@/pages/settings/advanced-settings.form';

const values: AdvancedSettingsFormValues = {
  advanced: { points_label_singular: 'Star', points_label_plural: 'Stars', max_points_per_day: 5 },
};

describe('buildAdvancedSettingsUpdate', () => {
  it('sends only the fraud limit when only it changed, so no label is stored', () => {
    expect(buildAdvancedSettingsUpdate(values, { advanced: { max_points_per_day: true } })).toEqual({
      advanced: { max_points_per_day: 5 },
    });
  });

  it('sends only the edited label', () => {
    expect(buildAdvancedSettingsUpdate(values, { advanced: { points_label_plural: true } })).toEqual({
      advanced: { points_label_plural: 'Stars' },
    });
  });

  it('sends nothing when nothing changed', () => {
    expect(buildAdvancedSettingsUpdate(values, {})).toEqual({});
  });
});

describe('advancedSettingsFormSchema', () => {
  const withPlural = (plural: string) => ({ advanced: { ...values.advanced, points_label_plural: plural } });

  it('rejects a blank label', () => {
    expect(advancedSettingsFormSchema.safeParse(withPlural('   ')).success).toBe(false);
  });

  it('rejects a label over 30 characters and accepts one at 30', () => {
    expect(advancedSettingsFormSchema.safeParse(withPlural('x'.repeat(31))).success).toBe(false);
    expect(advancedSettingsFormSchema.safeParse(withPlural('x'.repeat(30))).success).toBe(true);
  });
});
