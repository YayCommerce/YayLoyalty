import { z } from 'zod';

import { Settings, settingsSchema, SettingsUpdate } from '@/lib/schema/settings.schema';

/** Mirrors SettingsHelper::POINTS_LABEL_MAX_LENGTH. */
export const POINTS_LABEL_MAX_LENGTH = 30;

const pointsLabel = z.string().trim().min(1).max(POINTS_LABEL_MAX_LENGTH);

/** The Advanced tab's one form: the free points naming, plus Pro's fraud limit (never dirty in Lite). */
export const advancedSettingsFormSchema = z.object({
  general: z.object({
    points_label_singular: pointsLabel,
    points_label_plural: pointsLabel,
  }),
  advanced: settingsSchema.shape.advanced.pick({ max_points_per_day: true }),
});

export type AdvancedSettingsFormValues = z.infer<typeof advancedSettingsFormSchema>;

type FormGroup = keyof AdvancedSettingsFormValues;

/** react-hook-form's dirtyFields for this form: true per edited field. */
export type AdvancedSettingsDirtyFields = {
  [Group in FormGroup]?: Partial<Record<keyof AdvancedSettingsFormValues[Group], boolean>>;
};

export function advancedSettingsDefaults(settings: Settings): AdvancedSettingsFormValues {
  return {
    general: {
      points_label_singular: settings.general.points_label_singular,
      points_label_plural: settings.general.points_label_plural,
    },
    advanced: { max_points_per_day: settings.advanced.max_points_per_day },
  };
}

/**
 * The save payload: only the edited fields. Sending an untouched label would store the translated
 * default, which would then stop following the site language.
 */
export function buildAdvancedSettingsUpdate(
  values: AdvancedSettingsFormValues,
  dirty: AdvancedSettingsDirtyFields,
): SettingsUpdate {
  const update: Record<string, Record<string, unknown>> = {};

  for (const group of Object.keys(values) as FormGroup[]) {
    const fields = Object.entries(values[group]).filter(
      ([field]) => (dirty[group] as Record<string, boolean> | undefined)?.[field],
    );

    if (fields.length > 0) {
      update[group] = Object.fromEntries(fields);
    }
  }

  return update as SettingsUpdate;
}
