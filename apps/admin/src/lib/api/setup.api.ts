import { api } from '@/lib/api/api';
import { Setup } from '@/lib/schema/setup.schema';

export function getSetup() {
  return api.get('setup').json<Setup>();
}

export function completeSetup(data: Setup) {
  return api.post('setup', { json: data }).json<Setup>();
}
