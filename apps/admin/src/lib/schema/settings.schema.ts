import { z } from 'zod';

/** Backbone settings shape — extend per feature (points, rewards, referral, vip). */
export const settingsSchema = z.object({
  general: z.object({
    enabled: z.boolean(),
    points_label_singular: z.string(),
    points_label_plural: z.string(),
  }),
});

export type Settings = z.infer<typeof settingsSchema>;
