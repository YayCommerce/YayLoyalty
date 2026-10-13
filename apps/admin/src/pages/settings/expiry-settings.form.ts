import { z } from 'zod';

import { Settings, settingsSchema, SettingsUpdate } from '@/lib/schema/settings.schema';

/**
 * The Expiry tab's form over the `expiry` settings group: the free inactivity fields, plus Pro's model
 * and reminder fields, which only Pro's sections edit (never dirty in Lite, so never sent).
 */
export const expirySettingsFormSchema = settingsSchema.shape.expiry.pick({
  enabled: true,
  inactivity_days: true,
  reminder_days: true,
  model: true,
  batch_months: true,
  final_reminder_days: true,
  reminder_min_points: true,
});

export type ExpirySettingsFormValues = z.infer<typeof expirySettingsFormSchema>;

/** react-hook-form's dirtyFields for this form: true per edited field. */
export type ExpirySettingsDirtyFields = Partial<Record<keyof ExpirySettingsFormValues, boolean>>;

export function expirySettingsDefaults(settings: Settings): ExpirySettingsFormValues {
  return {
    enabled: settings.expiry.enabled,
    inactivity_days: settings.expiry.inactivity_days,
    reminder_days: settings.expiry.reminder_days,
    model: settings.expiry.model,
    batch_months: settings.expiry.batch_months,
    final_reminder_days: settings.expiry.final_reminder_days,
    reminder_min_points: settings.expiry.reminder_min_points,
  };
}

/** The save payload: only the edited fields, inside the `expiry` group, so the Pro keys stay as stored. */
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
