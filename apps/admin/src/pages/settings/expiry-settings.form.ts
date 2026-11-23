import { z } from 'zod';
import { __, _n, sprintf } from '@wordpress/i18n';

import { formatDaysFromNow } from '@/lib/helpers/format.helper';
import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { Settings, settingsSchema, SettingsUpdate } from '@/lib/schema/settings.schema';

type Expiry = Settings['expiry'];

/** Whether these settings run batch expiry. Mirrors ExpiryPolicy::mode(): Lite runs a stored batch mode as inactivity. */
function isBatchMode(expiry: Expiry): boolean {
  return IS_PRO_BUILD && expiry.mode === 'batch';
}

/** The window the settings run on, in days. Mirrors ExpiryPolicy::window_days(): a month counts as 28 days. */
function windowDays(expiry: Expiry): number {
  return isBatchMode(expiry) ? expiry.batch_months * 28 : expiry.inactivity_days;
}

/**
 * The Expiry tab's one form: the whole `expiry` group, Pro's mode and batch fields included. The reminder
 * must go out fewer days ahead than the window while expiry is on, as SettingsHelper::is_valid() rules.
 */
export const expirySettingsFormSchema = settingsSchema.pick({ expiry: true }).superRefine(({ expiry }, ctx) => {
  // Only while expiry is on: the fields are disabled while it is off, so a stored pair must not block turning it off.
  if (expiry.enabled && expiry.reminder_days >= windowDays(expiry)) {
    ctx.addIssue({
      code: 'custom',
      path: ['expiry', 'reminder_days'],
      message: __('Send the reminder fewer days ahead than the expiry window.', 'yayloyalty'),
    });
  }
});

export type ExpirySettingsFormValues = z.infer<typeof expirySettingsFormSchema>;

export function expirySettingsDefaults(settings: Settings): ExpirySettingsFormValues {
  return { expiry: settings.expiry };
}

/** The whole group: the server re-applies every date when any of it changes. */
export function buildExpirySettingsUpdate(values: ExpirySettingsFormValues): SettingsUpdate {
  return { expiry: values.expiry };
}

/**
 * "Month D, YYYY, N days from today": the earliest expiry date a settings change may give anyone. The grace,
 * capped at the window as ExpiryPolicy::grace_days() caps it.
 */
export function graceFloorText(expiry: Expiry): string {
  const days = Math.min(window.yayLoyaltyMeta.loyaltyMeta.expiryGraceDays, windowDays(expiry));
  const floor = formatDaysFromNow(days);

  /* translators: 1: a date, 2: number of days */
  return sprintf(_n('%1$s, %2$d day from today', '%1$s, %2$d days from today', days, 'yayloyalty'), floor, days);
}

/**
 * Which confirmation a save needs before it goes out: the one-way switch to batch, turning expiry on,
 * or a shorter window, where the admin also picks what happens to stored dates. Null when none.
 */
export function confirmationKind(saved: Expiry, next: Expiry): 'enable' | 'shorten' | 'batch' | null {
  if (next.mode === 'batch' && saved.mode !== 'batch') {
    return 'batch';
  }

  if (!next.enabled) {
    return null;
  }

  if (!saved.enabled) {
    return 'enable';
  }

  // Only the window the next mode runs on counts.
  const isSooner = isBatchMode(next)
    ? next.batch_months < saved.batch_months
    : next.inactivity_days < saved.inactivity_days;

  return isSooner ? 'shorten' : null;
}
