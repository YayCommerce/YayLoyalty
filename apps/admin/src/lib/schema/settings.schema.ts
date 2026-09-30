import { z } from 'zod';

/** Mirrors SettingsHelper, the source of truth. */
export const settingsSchema = z.object({
  general: z.object({
    enabled: z.boolean(),
    points_label_singular: z.string(),
    points_label_plural: z.string(),
    /** Role slugs that never earn. */
    excluded_roles: z.array(z.string()),
  }),
  advanced: z.object({
    /** Largest manual adjustment a non-administrator may make; 0 = unlimited. */
    adjustment_ceiling: z.number().int().nonnegative(),
  }),
  emails: z.object({
    /** Weekly per-customer cap on loyalty emails. */
    throttle_per_week: z.number().int().nonnegative(),
  }),
  /** Stamped by SettingsHelper on save; PHP reports SCHEMA_VERSION when the stored blob predates versioning. */
  schema_version: z.number().int().optional(),
});

export type Settings = z.infer<typeof settingsSchema>;
