import { describe, expect, it } from 'vitest';

import {
  buildGeneralSettingsUpdate,
  generalSettingsFormSchema,
  GeneralSettingsFormValues,
} from '@/pages/settings/general-settings.form';

const values: GeneralSettingsFormValues = {
  enabled: true,
  points_label_singular: 'Star',
  points_label_plural: 'Stars',
  max_points_per_day: 5,
  currency_include_fee: true,
};

describe('buildGeneralSettingsUpdate', () => {
  it('sends only the fraud limit when only it changed, so no label is stored', () => {
    expect(buildGeneralSettingsUpdate(values, { max_points_per_day: true })).toEqual({ max_points_per_day: 5 });
  });

  it('sends only the edited label', () => {
    expect(buildGeneralSettingsUpdate(values, { points_label_plural: true })).toEqual({
      points_label_plural: 'Stars',
    });
  });

  it('sends nothing when nothing changed', () => {
    expect(buildGeneralSettingsUpdate(values, {})).toEqual({});
  });
});

describe('generalSettingsFormSchema', () => {
  const withPlural = (plural: string) => ({ ...values, points_label_plural: plural });

  it('rejects a blank label', () => {
    expect(generalSettingsFormSchema.safeParse(withPlural('   ')).success).toBe(false);
  });

  it('rejects a label over 30 characters and accepts one at 30', () => {
    expect(generalSettingsFormSchema.safeParse(withPlural('x'.repeat(31))).success).toBe(false);
    expect(generalSettingsFormSchema.safeParse(withPlural('x'.repeat(30))).success).toBe(true);
  });
});
