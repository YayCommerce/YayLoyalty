import { api } from '@/lib/api/api';
import { activityEnvelopeSchema, ActivityGroup } from '@/lib/schema/activity.schema';
import { redemptionEnvelopeSchema } from '@/lib/schema/redemption.schema';
import { referralEnvelopeSchema } from '@/lib/schema/referral.schema';

/**
 * The drawer's three paginated tab-body fetchers — Activity log, Redemptions,
 * Referrals. Split out of `customer.api.ts` (phase 06) once that file passed
 * its 200-line cap; `getCustomer()`/`getCustomerLedger()`/`getCustomers()`
 * stay there since callers outside the drawer also use them. Same
 * `.parse()`-everything discipline as `customer.api.ts` (see its own
 * docblock); `scenario` only ever comes from `useDevScenario()`.
 */

/** App-side params for `getCustomerActivity()` — mirrors `CustomerLedgerParams`' shape. */
export type CustomerActivityParams = {
  page?: number;
  perPage?: number;
  group?: ActivityGroup;
  search?: string;
  after?: string;
  before?: string;
  scenario?: string;
};

/**
 * `GET /customers/{id}/activity`. The real path always answers an empty
 * envelope (no `activity` table yet).
 */
export async function getCustomerActivity(userId: number, params: CustomerActivityParams = {}) {
  const searchParams: Record<string, string> = {};

  if (params.page) searchParams.page = String(params.page);
  if (params.perPage) searchParams.per_page = String(params.perPage);
  if (params.group && params.group !== 'all') searchParams.group = params.group;
  if (params.search?.trim()) searchParams.search = params.search.trim();
  if (params.after) searchParams.after = params.after;
  if (params.before) searchParams.before = params.before;
  if (params.scenario) searchParams.scenario = params.scenario;

  const payload = await api.get(`customers/${userId}/activity`, { searchParams }).json();

  return activityEnvelopeSchema.parse(payload);
}

/** App-side params for `getCustomerRedemptions()`. */
export type CustomerRedemptionsParams = {
  page?: number;
  perPage?: number;
  scenario?: string;
};

/**
 * `GET /customers/{id}/redemptions`. The real path always answers an empty
 * envelope (no `redemptions` table yet).
 */
export async function getCustomerRedemptions(userId: number, params: CustomerRedemptionsParams = {}) {
  const searchParams: Record<string, string> = {};

  if (params.page) searchParams.page = String(params.page);
  if (params.perPage) searchParams.per_page = String(params.perPage);
  if (params.scenario) searchParams.scenario = params.scenario;

  const payload = await api.get(`customers/${userId}/redemptions`, { searchParams }).json();

  return redemptionEnvelopeSchema.parse(payload);
}

/** App-side params for `getCustomerReferrals()`. */
export type CustomerReferralsParams = {
  page?: number;
  perPage?: number;
  scenario?: string;
};

/**
 * `GET /customers/{id}/referrals`. The real path always answers an empty
 * envelope (no `referrals` table yet). Parsed with `referralEnvelopeSchema`, not
 * the generic `parsePaginated()` helper, which would drop `referral_link`/`campaign`.
 */
export async function getCustomerReferrals(userId: number, params: CustomerReferralsParams = {}) {
  const searchParams: Record<string, string> = {};

  if (params.page) searchParams.page = String(params.page);
  if (params.perPage) searchParams.per_page = String(params.perPage);
  if (params.scenario) searchParams.scenario = params.scenario;

  const payload = await api.get(`customers/${userId}/referrals`, { searchParams }).json();

  return referralEnvelopeSchema.parse(payload);
}
