import { api } from '@/lib/api/api';
import { activityEnvelopeSchema, ActivityGroup } from '@/lib/schema/activity.schema';
import { redemptionEnvelopeSchema } from '@/lib/schema/redemption.schema';
import { referralEnvelopeSchema } from '@/lib/schema/referral.schema';

/** The drawer's tab fetchers, plus the paging params that `getCustomerLedger()` reuses. */

/** App-side (camelCase) paging/search params every drawer tab fetcher takes; the wire spelling lives in `toDrawerSearchParams()`. */
export type DrawerPageParams = {
  page?: number;
  perPage?: number;
  search?: string;
  after?: string;
  before?: string;
};

export function toDrawerSearchParams(params: DrawerPageParams): Record<string, string> {
  const searchParams: Record<string, string> = {};

  if (params.page) searchParams.page = String(params.page);
  if (params.perPage) searchParams.per_page = String(params.perPage);
  if (params.search?.trim()) searchParams.search = params.search.trim();
  if (params.after) searchParams.after = params.after;
  if (params.before) searchParams.before = params.before;

  return searchParams;
}

export type CustomerActivityParams = DrawerPageParams & { group?: ActivityGroup };

/** Redemptions and Referrals take paging only. */
export type DrawerPageOnlyParams = Pick<DrawerPageParams, 'page' | 'perPage'>;

/** `GET /customers/{id}/activity`. */
export async function getCustomerActivity(userId: number, params: CustomerActivityParams = {}) {
  const searchParams = toDrawerSearchParams(params);

  if (params.group && params.group !== 'all') searchParams.group = params.group;

  const payload = await api.get(`customers/${userId}/activity`, { searchParams }).json();

  return activityEnvelopeSchema.parse(payload);
}

/** `GET /customers/{id}/redemptions`. */
export async function getCustomerRedemptions(userId: number, params: DrawerPageOnlyParams = {}) {
  const payload = await api
    .get(`customers/${userId}/redemptions`, { searchParams: toDrawerSearchParams(params) })
    .json();

  return redemptionEnvelopeSchema.parse(payload);
}

/**
 * `GET /customers/{id}/referrals`. Parsed with `referralEnvelopeSchema`, not `parsePaginated()`,
 * which would drop `referral_link` and `campaign`.
 */
export async function getCustomerReferrals(userId: number, params: DrawerPageOnlyParams = {}) {
  const payload = await api.get(`customers/${userId}/referrals`, { searchParams: toDrawerSearchParams(params) }).json();

  return referralEnvelopeSchema.parse(payload);
}
