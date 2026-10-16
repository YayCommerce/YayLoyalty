import * as v from 'valibot';

/** Mirrors SetupService::payload(), the source of truth. POST /setup takes it and returns the saved values. */
export const setupSchema = v.object({
  points_label_singular: v.string(),
  points_label_plural: v.string(),
  /** The built-in purchase campaign's rate: points per 1 currency unit. */
  purchase: v.object({ points_per_unit: v.number() }),
  /** The built-in signup and review campaigns: enabled means status active, otherwise paused. */
  signup: v.object({ enabled: v.boolean(), points: v.pipe(v.number(), v.integer()) }),
  review: v.object({ enabled: v.boolean(), points: v.pipe(v.number(), v.integer()) }),
  /** The built-in cart-discount reward. max_percent_of_subtotal null = no limit (100%). */
  cart_discount: v.object({
    points_per_currency_unit: v.number(),
    min_points: v.pipe(v.number(), v.integer()),
    max_percent_of_subtotal: v.nullable(v.number()),
  }),
  brand_color: v.string(),
});

export type Setup = v.InferOutput<typeof setupSchema>;
