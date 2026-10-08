import { z } from 'zod';

import { Settings, settingsSchema, SettingsUpdate } from '@/lib/schema/settings.schema';

/** Mirrors SettingsHelper::POINTS_LABEL_MAX_LENGTH. */
export const POINTS_LABEL_MAX_LENGTH = 30;

const pointsLabel = z.string().trim().min(1).max(POINTS_LABEL_MAX_LENGTH);

/** The Advanced tab's one form: the free points naming, plus Pro's fraud limit (never dirty in Lite). */
export const advancedSettingsFormSchema = settingsSchema.pick({ max_points_per_day: true }).extend({
  points_label_singular: pointsLabel,
  points_label_plural: pointsLabel,
});

export type AdvancedSettingsFormValues = z.infer<typeof advancedSettingsFormSchema>;

/** react-hook-form's dirtyFields for this form: true per edited field. */
export type AdvancedSettingsDirtyFields = Partial<Record<keyof AdvancedSettingsFormValues, boolean>>;

export function advancedSettingsDefaults(settings: Settings): AdvancedSettingsFormValues {
  return {
    points_label_singular: settings.points_label_singular,
    points_label_plural: settings.points_label_plural,
    max_points_per_day: settings.max_points_per_day,
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
  return Object.fromEntries(
    Object.entries(values).filter(([field]) => dirty[field as keyof AdvancedSettingsFormValues]),
  ) as SettingsUpdate;
}
