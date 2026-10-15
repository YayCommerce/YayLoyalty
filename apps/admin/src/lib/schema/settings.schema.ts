import { z } from 'zod';

/** Mirrors SettingsHelper, the source of truth. */
export const settingsSchema = z.object({
  enabled: z.boolean(),
  /** Role slugs that never earn. */
  excluded_roles: z.array(z.string()),
  points_label_singular: z.string(),
  points_label_plural: z.string(),
  /** Most points one customer earns automatically per site day; null = no limit. Enforced in Pro. */
  max_points_per_day: z.number().int().min(1).nullable(),
  emails: z.object({
    /** Weekly per-customer cap on loyalty emails; null = no cap. */
    throttle_per_week: z.number().int().min(1).nullable(),
  }),
  /** Points expiry, read through ExpiryPolicy. Lite keeps mode and spend_order but runs inactivity only. */
  expiry: z.object({
    enabled: z.boolean(),
    /** One-way: the server refuses batch → inactivity. */
    mode: z.enum(['inactivity', 'batch']),
    /** Max mirrors SettingsHelper::EXPIRY_WINDOW_MAX. */
    inactivity_days: z.number().int().min(1).max(36500),
    batch_months: z.number().int().min(1).max(36500),
    spend_order: z.enum(['oldest', 'newest']),
    /** What shortening the window does to stored dates; picked in the save's confirmation. */
    on_shorten: z.enum(['keep', 'apply']),
    /** Days before expiry the Points expiring soon email goes out. Max mirrors SettingsHelper::REMINDER_DAYS_MAX. */
    reminder_days: z.number().int().min(1).max(365),
  }),
  /** Stamped by SettingsHelper on save; PHP reports SCHEMA_VERSION when the stored blob predates versioning. */
  schema_version: z.number().int().optional(),
});

export type Settings = z.infer<typeof settingsSchema>;

/**
 * A save payload: only the fields a tab owns. SettingsHelper writes only what is sent,
 * so a tab never stores another tab's values, nor translated defaults such as the point labels.
 */
export type SettingsUpdate = {
  [Key in Exclude<keyof Settings, 'schema_version'>]?: Settings[Key] extends unknown[]
    ? Settings[Key]
    : Settings[Key] extends object
      ? Partial<Settings[Key]>
      : Settings[Key];
};
