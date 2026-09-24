import { api, wordpressApi } from '@/lib/api/api';
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

export type WpPageOption = { id: number; title: { rendered: string } };

/** How many pages the picker lists at once; anything past it is reached by searching. */
export const PAGE_PICKER_LIMIT = 20;

const PAGE_FIELDS = { status: 'publish', orderby: 'title', order: 'asc', _fields: 'id,title' };

/** The first PAGE_PICKER_LIMIT published pages, narrowed by `search` when given. */
export function searchPublishedPages(search: string) {
  return wordpressApi
    .get('pages', { searchParams: { ...PAGE_FIELDS, per_page: String(PAGE_PICKER_LIMIT), ...(search && { search }) } })
    .json<WpPageOption[]>();
}

/** The chosen pages by id, so their names show even when a search leaves them out of the list. */
export function getPublishedPagesByIds(ids: number[]) {
  return wordpressApi
    .get('pages', { searchParams: { ...PAGE_FIELDS, per_page: String(ids.length), include: ids.join(',') } })
    .json<WpPageOption[]>();
}
