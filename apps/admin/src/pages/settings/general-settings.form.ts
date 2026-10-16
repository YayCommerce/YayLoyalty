import * as v from 'valibot';

import { Settings, settingsSchema, SettingsUpdate } from '@/lib/schema/settings.schema';

/** Mirrors SettingsHelper::POINTS_LABEL_MAX_LENGTH, the source of truth. */
export const POINTS_LABEL_MAX_LENGTH = 30;

const pointsLabel = v.pipe(v.string(), v.trim(), v.minLength(1), v.maxLength(POINTS_LABEL_MAX_LENGTH));

/** The General tab's one form: the free points naming, plus Pro's fraud limit (never dirty in Lite). */
export const generalSettingsFormSchema = v.object({
  max_points_per_day: settingsSchema.entries.max_points_per_day,
  points_label_singular: pointsLabel,
  points_label_plural: pointsLabel,
});

export type GeneralSettingsFormValues = v.InferOutput<typeof generalSettingsFormSchema>;

/** react-hook-form's dirtyFields for this form: true per edited field. */
export type GeneralSettingsDirtyFields = Partial<Record<keyof GeneralSettingsFormValues, boolean>>;

export function generalSettingsDefaults(settings: Settings): GeneralSettingsFormValues {
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
export function buildGeneralSettingsUpdate(
  values: GeneralSettingsFormValues,
  dirty: GeneralSettingsDirtyFields,
): SettingsUpdate {
  return Object.fromEntries(
    Object.entries(values).filter(([field]) => dirty[field as keyof GeneralSettingsFormValues]),
  ) as SettingsUpdate;
}
