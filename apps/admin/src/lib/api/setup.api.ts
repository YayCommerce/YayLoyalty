import { api } from '@/lib/api/api';
import { Setup, setupSchema } from '@/lib/schema/setup.schema';

export async function completeSetup(data: Setup) {
  return setupSchema.parse(await api.post('setup', { json: data }).json());
}

export async function dismissSetup() {
  await api.post('setup/dismiss');
}
