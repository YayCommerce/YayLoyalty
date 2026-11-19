import { api } from '@/lib/api/api';
import { ReferralSettings, referralSettingsSchema, ReferralSettingsUpdate } from './referral-settings.schema';

export async function getReferralSettings(): Promise<ReferralSettings> {
  return referralSettingsSchema.parse(await api.get('referral/settings').json());
}

/** POST referral/settings: the server saves the sent fields over the stored ones and returns the result. */
export async function postReferralSettings(data: ReferralSettingsUpdate): Promise<ReferralSettings> {
  return referralSettingsSchema.parse(await api.post('referral/settings', { json: data }).json());
}
