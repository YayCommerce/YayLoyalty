import { api } from '@/lib/api/api';
import { toDrawerSearchParams, type DrawerPageParams } from '@/lib/api/customer-drawer.api';
import { ReferralSettings, referralSettingsSchema, ReferralSettingsUpdate } from './referral-settings.schema';
import { referralListEnvelopeSchema, type ReferralStatus } from './referrals.schema';

export type ReferralListParams = Pick<DrawerPageParams, 'page' | 'perPage' | 'search'> & {
  status?: ReferralStatus;
  orderby?: 'id' | 'hold_until';
};

/** GET referrals: a page of referrals plus the store-wide counts per status. */
export async function getReferrals(params: ReferralListParams = {}, signal?: AbortSignal) {
  const searchParams = toDrawerSearchParams(params);

  if (params.status) searchParams.status = params.status;
  if (params.orderby) searchParams.orderby = params.orderby;

  return referralListEnvelopeSchema.parse(await api.get('referrals', { searchParams, signal }).json());
}

export async function getReferralSettings(): Promise<ReferralSettings> {
  return referralSettingsSchema.parse(await api.get('referral/settings').json());
}

/** POST referral/settings: the server saves the sent fields over the stored ones and returns the result. */
export async function postReferralSettings(data: ReferralSettingsUpdate): Promise<ReferralSettings> {
  return referralSettingsSchema.parse(await api.post('referral/settings', { json: data }).json());
}
