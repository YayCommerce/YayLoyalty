import { z } from 'zod';

import { Settings, settingsSchema, SettingsUpdate } from '@/lib/schema/settings.schema';

/** The Expiry tab's form: the free inactivity fields of the `expiry` settings group. */
export const expirySettingsFormSchema = settingsSchema.shape.expiry.pick({
  enabled: true,
  inactivity_days: true,
  reminder_days: true,
});

export type ExpirySettingsFormValues = z.infer<typeof expirySettingsFormSchema>;

/** react-hook-form's dirtyFields for this form: true per edited field. */
export type ExpirySettingsDirtyFields = Partial<Record<keyof ExpirySettingsFormValues, boolean>>;

export function expirySettingsDefaults(settings: Settings): ExpirySettingsFormValues {
  return {
    enabled: settings.expiry.enabled,
    inactivity_days: settings.expiry.inactivity_days,
    reminder_days: settings.expiry.reminder_days,
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
