import { api } from '@/lib/api/api';
import { toDrawerSearchParams, type DrawerPageParams } from '@/lib/api/customer-drawer.api';
import { ReferralSettings, referralSettingsSchema, ReferralSettingsUpdate } from './referral-settings.schema';
import type { AdminRejectReason } from './approvals/approvals.copy';
import { customerReferralsSchema } from './drawer/customer-referrals.schema';
import { referralListEnvelopeSchema, type ReferralStatus } from './referrals.schema';

export type ReferralListParams = Pick<DrawerPageParams, 'page' | 'perPage' | 'search'> & {
  status?: ReferralStatus;
  orderby?: 'id' | 'hold_until';
};

/** POST referrals/{id}/approve: pays a held referral now. A 409 means it was already paid or rejected, or can't be paid now. */
export async function approveReferral(id: number): Promise<void> {
  await api.post(`referrals/${id}/approve`);
}

/** POST referrals/{id}/reject with one of the admin's reasons. A 409 means it was no longer on hold. */
export async function rejectReferral(id: number, reason: AdminRejectReason): Promise<void> {
  await api.post(`referrals/${id}/reject`, { json: { reason } });
}

/** GET customers/{id}/referrals: one customer as advocate, for the drawer. Creates their referral code on first view. */
export async function getCustomerReferrals(userId: number, page: number, signal?: AbortSignal) {
  return customerReferralsSchema.parse(
    await api.get(`customers/${userId}/referrals`, { searchParams: { page: String(page) }, signal }).json(),
  );
}

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
