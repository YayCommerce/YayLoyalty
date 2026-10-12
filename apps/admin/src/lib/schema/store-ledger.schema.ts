import { z } from 'zod';

import { paginatedSchema } from '@/lib/api/paginated';
import { ledgerEntrySchema, ledgerTypeCountsSchema } from './ledger.schema';

/** Mirrors YayLoyalty\Pro\Controllers\StoreLedgerRestController::define_item_schema(), the source of truth. */
export const storeLedgerEntrySchema = ledgerEntrySchema.extend({
  customer_name: z.string(),
  customer_email: z.string(),
});

export type StoreLedgerEntry = z.infer<typeof storeLedgerEntrySchema>;

/** `GET /ledger`'s envelope: the paginated envelope plus store-wide, unfiltered `type_counts`. */
export const storeLedgerEnvelopeSchema = paginatedSchema(storeLedgerEntrySchema).extend({
  type_counts: ledgerTypeCountsSchema,
});
