import { z } from 'zod';

/**
 * Mirrors LookupRestController, the source of truth.
 * One row per match, capped at LOOKUP_PAGE_SIZE. `label` is the name a merchant recognises, decoded to plain text.
 */

/** Mirrors `LookupRestController::ENTITIES`. */
export const LOOKUP_ENTITIES = ['products', 'categories', 'customers'] as const;

export type LookupEntity = (typeof LOOKUP_ENTITIES)[number];

/** Mirrors `LookupRestController::LIMIT`; a picker that gets this many should say the list is cut. */
export const LOOKUP_PAGE_SIZE = 20;

export const lookupItemSchema = z.object({
  id: z.number().int(),
  label: z.string(),
});

export type LookupItem = z.infer<typeof lookupItemSchema>;

export const lookupResponseSchema = z.object({
  items: z.array(lookupItemSchema),
});
