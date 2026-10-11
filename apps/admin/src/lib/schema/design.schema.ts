import { z } from 'zod';

/**
 * Mirrors DesignSchema, the source of truth.
 */

export const designLauncherPositionSchema = z.enum(['bottom_right', 'bottom_left']);
export type DesignLauncherPosition = z.infer<typeof designLauncherPositionSchema>;

export const designVisibilityScopeSchema = z.enum(['all_pages', 'shop_and_product', 'custom_pages']);
export type DesignVisibilityScope = z.infer<typeof designVisibilityScopeSchema>;

export const designPanelLayoutSchema = z.enum(['compact', 'full']);
export type DesignPanelLayout = z.infer<typeof designPanelLayoutSchema>;

export const designSchema = z.object({
  launcher: z.object({
    position: designLauncherPositionSchema,
    brand_color: z.string(),
    corner_radius: z.number().int().min(0).max(24),
  }),
  storefront_text: z.object({
    launcher_label: z.string().min(1),
    balance_heading: z.string().min(1),
    earn_rate_line: z.string(),
    redeem_button: z.string().min(1),
    signed_out_prompt: z.string(),
    product_earn_line: z.string().min(1),
    thankyou_pending_line: z.string().min(1),
    thankyou_earned_line: z.string().min(1),
  }),
  visibility: z.object({
    scope: designVisibilityScopeSchema,
    /** Read only when `scope === 'custom_pages'`. */
    include_page_ids: z.array(z.number().int().positive()),
    hide_on_mobile: z.boolean(),
    hide_for_roles: z.array(z.string()),
    product_message: z.boolean(),
    thankyou_message: z.boolean(),
  }),
  /** Pro. */
  panel: z.object({
    layout: designPanelLayoutSchema,
    show_reward_catalog: z.boolean(),
  }),
  /** Pro. */
  typography: z.object({
    custom_css: z.string(),
  }),
  /** Stamped by DesignHelper on save; a design never saved reports the current version. */
  schema_version: z.number().int().optional(),
});

export type Design = z.infer<typeof designSchema>;

/**
 * Two whole HTML pages from the server's own launcher templates: the button alone, and the open panel.
 * Mirrors the keys `LauncherPreview::documents()` returns, which owns them.
 */
export const designPreviewSchema = z.object({
  collapsed: z.string(),
  expanded: z.string(),
});

export type DesignPreview = z.infer<typeof designPreviewSchema>;
