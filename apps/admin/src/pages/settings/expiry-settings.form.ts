import { z } from 'zod';

import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { Settings, settingsSchema, SettingsUpdate } from '@/lib/schema/settings.schema';

/** The Expiry tab's one form: the whole `expiry` group, Pro's mode and batch fields included. */
export const expirySettingsFormSchema = settingsSchema.pick({ expiry: true });

export type ExpirySettingsFormValues = z.infer<typeof expirySettingsFormSchema>;

type Expiry = Settings['expiry'];

export function expirySettingsDefaults(settings: Settings): ExpirySettingsFormValues {
  return { expiry: settings.expiry };
}

/** The whole group: the server re-applies every date when any of it changes. */
export function buildExpirySettingsUpdate(values: ExpirySettingsFormValues): SettingsUpdate {
  return { expiry: values.expiry };
}

/**
 * Which confirmation a save needs before it goes out: the one-way switch to batch, or a change that
 * can bring expiry dates sooner (turning expiry on, or a shorter window). Null when none.
 */
export function needsConfirmation(saved: Expiry, next: Expiry): 'shorten' | 'batch' | null {
  if (next.mode === 'batch' && saved.mode !== 'batch') {
    return 'batch';
  }

  // Only the window the next mode runs on counts; Lite runs a stored batch mode as inactivity.
  const isBatch = IS_PRO_BUILD && next.mode === 'batch';
  const isSooner =
    !saved.enabled || (isBatch ? next.batch_months < saved.batch_months : next.inactivity_days < saved.inactivity_days);

  return next.enabled && isSooner ? 'shorten' : null;
}
