import * as v from 'valibot';

import { paginatedSchema } from '@/lib/api/paginated';
import { ledgerEntrySchema, ledgerTypeCountsSchema } from './ledger.schema';

/** Mirrors YayLoyalty\Pro\Controllers\StoreLedgerRestController::define_item_schema(), the source of truth. */
export const storeLedgerEntrySchema = v.object({
  ...ledgerEntrySchema.entries,
  customer_name: v.string(),
  customer_email: v.string(),
});

export type StoreLedgerEntry = v.InferOutput<typeof storeLedgerEntrySchema>;

/** `GET /ledger`'s envelope: the paginated envelope plus store-wide, unfiltered `type_counts`. */
export const storeLedgerEnvelopeSchema = v.object({
  ...paginatedSchema(storeLedgerEntrySchema).entries,
  type_counts: ledgerTypeCountsSchema,
});
