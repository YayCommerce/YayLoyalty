import * as v from 'valibot';

/**
 * Mirrors LookupRestController, the source of truth.
 * One row per match, capped at LOOKUP_PAGE_SIZE. `label` is the name a merchant recognises, decoded to plain text.
 */

/** Mirrors `LookupRestController::ENTITIES`, the source of truth. */
export const LOOKUP_ENTITIES = ['products', 'categories', 'customers'] as const;

export type LookupEntity = (typeof LOOKUP_ENTITIES)[number];

/** Mirrors `LookupRestController::LIMIT`, the source of truth. A picker that gets this many should say the list is cut. */
export const LOOKUP_PAGE_SIZE = 20;

export const lookupItemSchema = v.object({
  id: v.pipe(v.number(), v.integer()),
  label: v.string(),
});

export type LookupItem = v.InferOutput<typeof lookupItemSchema>;

export const lookupResponseSchema = v.object({
  items: v.array(lookupItemSchema),
});

/** Mirrors `LookupRestController::product_summary()` (`GET /lookups/products/{id}`), the source of truth. */
export const productSummarySchema = v.object({
  id: v.pipe(v.number(), v.integer()),
  name: v.string(),
  /** '' when the product has none. */
  sku: v.string(),
  /** Formatted in the store currency; '' when the product has no price. */
  price: v.string(),
  /** Comma-separated category names; '' when uncategorised. */
  categories: v.string(),
  image_url: v.nullable(v.string()),
  /** WooCommerce's own vocabulary: instock, outofstock, onbackorder. */
  stock_status: v.string(),
  /** Null when WooCommerce does not track a count for this product. */
  stock_quantity: v.nullable(v.pipe(v.number(), v.integer())),
});

export type ProductSummary = v.InferOutput<typeof productSummarySchema>;
