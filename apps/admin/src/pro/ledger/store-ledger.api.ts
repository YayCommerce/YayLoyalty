import { api } from '@/lib/api/api';
import { toDrawerSearchParams, type DrawerPageParams } from '@/lib/api/customer-drawer.api';
import type { LedgerFilterType } from '@/lib/schema/ledger.schema';
import { storeLedgerEnvelopeSchema } from '@/lib/schema/store-ledger.schema';

/** Client for the Pro-only `GET /ledger`. Lite has no such route (404, not 403). */

export type StoreLedgerParams = DrawerPageParams & {
  type?: LedgerFilterType;
  campaignId?: number;
  order?: 'asc' | 'desc';
};

export async function getStoreLedger(params: StoreLedgerParams = {}, signal?: AbortSignal) {
  const searchParams = toDrawerSearchParams(params);

  if (params.type && params.type !== 'all') searchParams.type = params.type;
  if (params.campaignId) searchParams.campaign_id = String(params.campaignId);
  if (params.order) searchParams.order = params.order;

  const payload = await api.get('ledger', { searchParams, signal }).json();

  return storeLedgerEnvelopeSchema.parse(payload);
}
