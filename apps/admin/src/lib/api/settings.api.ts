import { api } from '@/lib/api/api';
import { Settings, SettingsUpdate } from '@/lib/schema/settings.schema';

export function getSettings() {
  return api.get('settings').json<Settings>();
}

export function postSettings(data: SettingsUpdate) {
  return api.post('settings', { json: data }).json<Settings>();
}

export function markReviewed() {
  return api.post('mark-reviewed').json<boolean>();
}
