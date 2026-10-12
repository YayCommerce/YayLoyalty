import { z } from 'zod';

/**
 * Mirrors LookupRestController, the source of truth.
 * One row per match, capped at LOOKUP_PAGE_SIZE. `label` is the name a merchant recognises, decoded to plain text.
 */

/** Mirrors `LookupRestController::ENTITIES`, the source of truth. */
export const LOOKUP_ENTITIES = ['products', 'categories', 'customers'] as const;

export type LookupEntity = (typeof LOOKUP_ENTITIES)[number];

/** Mirrors `LookupRestController::LIMIT`, the source of truth. A picker that gets this many should say the list is cut. */
export const LOOKUP_PAGE_SIZE = 20;

export const lookupItemSchema = z.object({
  id: z.number().int(),
  label: z.string(),
});

export type LookupItem = z.infer<typeof lookupItemSchema>;

export const lookupResponseSchema = z.object({
  items: z.array(lookupItemSchema),
});

/** Mirrors `LookupRestController::product_summary()` (`GET /lookups/products/{id}`), the source of truth. */
export const productSummarySchema = z.object({
  id: z.number().int(),
  name: z.string(),
  /** '' when the product has none. */
  sku: z.string(),
  /** Formatted in the store currency; '' when the product has no price. */
  price: z.string(),
  /** Comma-separated category names; '' when uncategorised. */
  categories: z.string(),
  image_url: z.string().nullable(),
  /** WooCommerce's own vocabulary: instock, outofstock, onbackorder. */
  stock_status: z.string(),
  /** Null when WooCommerce does not track a count for this product. */
  stock_quantity: z.number().int().nullable(),
});

export type ProductSummary = z.infer<typeof productSummarySchema>;
