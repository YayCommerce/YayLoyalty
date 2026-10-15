import { z } from 'zod';
import { __, _n, sprintf } from '@wordpress/i18n';

import { parseWPDate } from '@/lib/helpers/format.helper';
import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { Settings, settingsSchema, SettingsUpdate } from '@/lib/schema/settings.schema';

/**
 * The Expiry tab's one form: the whole `expiry` group, Pro's mode and batch fields included. The reminder
 * must go out fewer days ahead than the window, as SettingsHelper::is_valid() and ExpiryPolicy::window_days() rule.
 */
export const expirySettingsFormSchema = settingsSchema.pick({ expiry: true }).superRefine(({ expiry }, ctx) => {
  // Lite runs a stored batch mode as inactivity; a month counts as 28 days.
  const windowDays = IS_PRO_BUILD && expiry.mode === 'batch' ? expiry.batch_months * 28 : expiry.inactivity_days;

  if (expiry.reminder_days >= windowDays) {
    ctx.addIssue({
      code: 'custom',
      path: ['expiry', 'reminder_days'],
      message: __('Send the reminder fewer days ahead than the expiry window.', 'yayloyalty'),
    });
  }
});

export type ExpirySettingsFormValues = z.infer<typeof expirySettingsFormSchema>;

type Expiry = Settings['expiry'];

export function expirySettingsDefaults(settings: Settings): ExpirySettingsFormValues {
  return { expiry: settings.expiry };
}

/** The whole group: the server re-applies every date when any of it changes. */
export function buildExpirySettingsUpdate(values: ExpirySettingsFormValues): SettingsUpdate {
  return { expiry: values.expiry };
}

/** "Month D, YYYY, N days from today": the earliest expiry date a settings change may give anyone. */
export function graceFloorText(): string {
  const days = window.yayLoyaltyMeta.loyaltyMeta.expiryGraceDays;
  const floor = parseWPDate(new Date(Date.now() + days * 86_400_000));

  /* translators: 1: a date, 2: number of days */
  return sprintf(_n('%1$s, %2$d day from today', '%1$s, %2$d days from today', days, 'yayloyalty'), floor, days);
}

/**
 * Which confirmation a save needs before it goes out: the one-way switch to batch, turning expiry on,
 * or a shorter window, where the admin also picks what happens to stored dates. Null when none.
 */
export function needsConfirmation(saved: Expiry, next: Expiry): 'enable' | 'shorten' | 'batch' | null {
  if (next.mode === 'batch' && saved.mode !== 'batch') {
    return 'batch';
  }

  if (!next.enabled) {
    return null;
  }

  if (!saved.enabled) {
    return 'enable';
  }

  // Only the window the next mode runs on counts; Lite runs a stored batch mode as inactivity.
  const isBatch = IS_PRO_BUILD && next.mode === 'batch';
  const isSooner = isBatch ? next.batch_months < saved.batch_months : next.inactivity_days < saved.inactivity_days;

  return isSooner ? 'shorten' : null;
}
