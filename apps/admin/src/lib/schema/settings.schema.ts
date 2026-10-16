import * as v from 'valibot';

/** Most days ahead an expiry reminder may go out. Mirrors SettingsHelper::REMINDER_DAYS_MAX. */
export const REMINDER_DAYS_MAX = 365;

/** Mirrors SettingsHelper, the source of truth. */
export const settingsSchema = v.object({
  enabled: v.boolean(),
  /** Role slugs that never earn. */
  excluded_roles: v.array(v.string()),
  points_label_singular: v.string(),
  points_label_plural: v.string(),
  /** Most points one customer earns automatically per site day; null = no limit. Enforced in Pro. */
  max_points_per_day: v.nullable(v.pipe(v.number(), v.integer(), v.minValue(1))),
  emails: v.object({
    /** Weekly per-customer cap on loyalty emails; null = no cap. */
    throttle_per_week: v.nullable(v.pipe(v.number(), v.integer(), v.minValue(1))),
  }),
  /** Points expiry, read through ExpiryPolicy. Lite keeps mode and spend_order but runs inactivity only. */
  expiry: v.object({
    enabled: v.boolean(),
    /** One-way: the server refuses batch → inactivity. */
    mode: v.picklist(['inactivity', 'batch']),
    /** Max mirrors SettingsHelper::EXPIRY_WINDOW_MAX. */
    inactivity_days: v.pipe(v.number(), v.integer(), v.minValue(1), v.maxValue(36500)),
    batch_months: v.pipe(v.number(), v.integer(), v.minValue(1), v.maxValue(36500)),
    spend_order: v.picklist(['oldest', 'newest']),
    /** What shortening the window does to stored dates; picked in the save's confirmation. */
    on_shorten: v.picklist(['keep', 'apply']),
    /** Days before expiry the Points expiring soon email goes out. */
    reminder_days: v.pipe(v.number(), v.integer(), v.minValue(1), v.maxValue(REMINDER_DAYS_MAX)),
  }),
  /** Stamped by SettingsHelper on save; PHP reports SCHEMA_VERSION when the stored blob predates versioning. */
  schema_version: v.optional(v.pipe(v.number(), v.integer())),
});

export type Settings = v.InferOutput<typeof settingsSchema>;

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
