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

/** Mirrors the `viewer` that `DesignRestController::preview_design()` reads, which owns it: `guest`, or anything else for the sample member. */
export type DesignPreviewViewer = 'member' | 'guest';

/**
 * Two whole HTML pages from the server's own launcher templates: the button alone, and the open panel.
 * Mirrors the keys `LauncherPreview::documents()` returns, which owns them.
 */
export type DesignPreview = { collapsed: string; expanded: string };

/**
 * The design fields the storefront launcher draws: a mirror of what `LauncherPayload::for_balance()`
 * (and `Launcher::view()`) read. A field missing here never redraws the preview; change both together.
 */
export const DESIGN_PREVIEW_FIELDS = ['launcher', 'storefront_text', 'visibility.hide_on_mobile'] as const;

/** DESIGN_PREVIEW_FIELDS as the request body; the server fills the rest from the stored design. */
export type DesignPreviewInput = Pick<Design, 'launcher' | 'storefront_text'> & {
  visibility: Pick<Design['visibility'], 'hide_on_mobile'>;
};

/** The storefront launcher drawn with an unsaved design; nothing is saved. */
export function previewDesign(design: DesignPreviewInput, viewer: DesignPreviewViewer) {
  return api.post('design/preview', { json: { design, viewer } }).json<DesignPreview>();
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
