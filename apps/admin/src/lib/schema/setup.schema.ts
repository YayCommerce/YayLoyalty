import { z } from 'zod';

/** Mirrors SetupService::payload(), the source of truth. POST /setup takes it and returns the saved values. */
export const setupSchema = z.object({
  points_label_singular: z.string(),
  points_label_plural: z.string(),
  /** The built-in purchase campaign's rate: points per 1 currency unit. */
  purchase: z.object({ points_per_unit: z.number() }),
  /** The built-in signup and review campaigns: enabled means status active, otherwise paused. */
  signup: z.object({ enabled: z.boolean(), points: z.number().int() }),
  review: z.object({ enabled: z.boolean(), points: z.number().int() }),
  /** The built-in cart-discount reward. max_percent_of_subtotal null = no limit (100%). */
  cart_discount: z.object({
    points_per_currency_unit: z.number(),
    min_points: z.number().int(),
    max_percent_of_subtotal: z.number().nullable(),
  }),
  brand_color: z.string(),
});

export type Setup = z.infer<typeof setupSchema>;
