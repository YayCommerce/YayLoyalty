import { api } from '@/lib/api/api';
import { Design } from '@/lib/schema/design.schema';

export function getDesign() {
  return api.get('design').json<Design>();
}

export function postDesign(data: Design) {
  return api.post('design', { json: data }).json<Design>();
}

export function resetDesign(group?: string) {
  return api.post('design/reset', { json: group ? { group } : {} }).json<Design>();
}
