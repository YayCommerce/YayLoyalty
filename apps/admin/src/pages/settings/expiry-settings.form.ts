import { z } from 'zod';

import { Settings, settingsSchema, SettingsUpdate } from '@/lib/schema/settings.schema';

/**
 * The Expiry tab's form: the whole `expiry` settings group. Pro's model and reminder fields are edited
 * only by Pro's sections, so in Lite they are never dirty and never sent.
 */
export const expirySettingsFormSchema = settingsSchema.shape.expiry;

export type ExpirySettingsFormValues = z.infer<typeof expirySettingsFormSchema>;

/** react-hook-form's dirtyFields for this form: true per edited field. */
export type ExpirySettingsDirtyFields = Partial<Record<keyof ExpirySettingsFormValues, boolean>>;

type RangedField = 'inactivity_days' | 'reminder_days' | 'batch_months' | 'final_reminder_days';

/** A field's bounds, read from the zod schema (which mirrors SettingsHelper::RANGES), so no input keeps its own copy. */
export function expiryFieldRange(field: RangedField): { min: number; max: number } {
  const number = expirySettingsFormSchema.shape[field];
  return { min: number.minValue ?? 0, max: number.maxValue ?? Number.MAX_SAFE_INTEGER };
}

export function expirySettingsDefaults(settings: Settings): ExpirySettingsFormValues {
  return { ...settings.expiry };
}

/** The save payload: only the edited fields, inside the `expiry` group, so untouched keys stay as stored. */
export function buildExpirySettingsUpdate(
  values: ExpirySettingsFormValues,
  dirty: ExpirySettingsDirtyFields,
): SettingsUpdate {
  return {
    expiry: Object.fromEntries(
      Object.entries(values).filter(([field]) => dirty[field as keyof ExpirySettingsFormValues]),
    ),
  };
}
