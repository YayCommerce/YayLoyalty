import { z } from 'zod';

/** Mirrors SettingsHelper, the source of truth. */
export const settingsSchema = z.object({
  general: z.object({
    enabled: z.boolean(),
    /** Role slugs that never earn. */
    excluded_roles: z.array(z.string()),
  }),
  advanced: z.object({
    points_label_singular: z.string(),
    points_label_plural: z.string(),
    /** Largest manual adjustment a non-administrator may make; 0 = unlimited. */
    adjustment_ceiling: z.number().int().nonnegative(),
    /** Most points one customer earns automatically per site day; 0 = no limit. Enforced in Pro. */
    max_points_per_day: z.number().int().nonnegative(),
  }),
  emails: z.object({
    /** Weekly per-customer cap on loyalty emails. */
    throttle_per_week: z.number().int().nonnegative(),
  }),
  /** Stamped by SettingsHelper on save; PHP reports SCHEMA_VERSION when the stored blob predates versioning. */
  schema_version: z.number().int().optional(),
});

export type Settings = z.infer<typeof settingsSchema>;

/**
 * A save payload: only the groups and fields a tab owns. SettingsHelper writes only what is sent,
 * so a tab never stores another tab's values, nor translated defaults such as the point labels.
 */
export type SettingsUpdate = { [Group in Exclude<keyof Settings, 'schema_version'>]?: Partial<Settings[Group]> };
