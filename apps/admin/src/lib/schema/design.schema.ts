import { z } from 'zod';

/**
 * Mirrors DesignSchema, the source of truth.
 */

export const designVisibilityScopeSchema = z.enum(['all_pages', 'shop_and_product', 'custom_pages']);
export type DesignVisibilityScope = z.infer<typeof designVisibilityScopeSchema>;

export const designPanelLayoutSchema = z.enum(['compact', 'full']);
export type DesignPanelLayout = z.infer<typeof designPanelLayoutSchema>;

export const designSchema = z.object({
  launcher: z.object({
    brand_color: z.string(),
    corner_radius: z.number().int().min(0).max(24),
  }),
  visibility: z.object({
    scope: designVisibilityScopeSchema,
    /** Read only when `scope === 'custom_pages'`. */
    include_page_ids: z.array(z.number().int().positive()),
    hide_on_mobile: z.boolean(),
    hide_for_roles: z.array(z.string()),
    show_product_message: z.boolean(),
    show_thankyou_message: z.boolean(),
    /** Pro: conditional campaigns and rewards teased on the storefront. */
    show_bonus_offers: z.boolean(),
    show_cart_earn_preview: z.boolean(),
  }),
  /** `{points}` and `{points_label}` are filled in on the storefront. */
  storefront_text: z.object({
    cart_earn_line: z.string().trim().min(1),
  }),
  /** Pro. */
  panel: z.object({
    layout: designPanelLayoutSchema,
    show_reward_catalog: z.boolean(),
  }),
  /** Pro. */
  custom_css: z.object({
    custom_css: z.string(),
  }),
  /** Stamped by DesignHelper on save; a design never saved reports the current version. */
  schema_version: z.number().int().optional(),
});

export type Design = z.infer<typeof designSchema>;

/**
 * Mirrors the keys `LauncherPreview::documents()` returns, the source of truth.
 * Two whole HTML pages from the server's own launcher templates: the button alone, and the open panel.
 */
export const designPreviewSchema = z.object({
  collapsed: z.string(),
  expanded: z.string(),
});

export type DesignPreview = z.infer<typeof designPreviewSchema>;
