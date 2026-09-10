import { z } from 'zod';

/**
 * Backbone settings shape — extend per feature (points, rewards, referral, vip).
 *
 * Mirrors SettingsHelper on the PHP side, which is the source of truth.
 */
export const settingsSchema = z.object({
  general: z.object({
    enabled: z.boolean(),
    points_label_singular: z.string(),
    points_label_plural: z.string(),
  }),
  /**
   * Stamped by SettingsHelper on every save so the stored shape can evolve
   * without a table migration. A blob written before versioning reads as 0.
   */
  schema_version: z.number().int().optional(),
});

export type Settings = z.infer<typeof settingsSchema>;
