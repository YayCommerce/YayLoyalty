import { z } from 'zod';

/**
 * Mirrors DesignSchema, the source of truth.
 */

export const designLauncherIconSchema = z.enum(['default', 'circle', 'diamond', 'bar', 'ring', 'line', 'custom']);
export type DesignLauncherIcon = z.infer<typeof designLauncherIconSchema>;

export const designLauncherPositionSchema = z.enum(['bottom_right', 'bottom_left', 'top_right', 'top_left']);
export type DesignLauncherPosition = z.infer<typeof designLauncherPositionSchema>;

export const designLauncherButtonStyleSchema = z.enum(['icon', 'label', 'both']);
export type DesignLauncherButtonStyle = z.infer<typeof designLauncherButtonStyleSchema>;

export const designVisibilityScopeSchema = z.enum(['all_pages', 'shop_and_product', 'custom_pages']);
export type DesignVisibilityScope = z.infer<typeof designVisibilityScopeSchema>;

export const designPanelLayoutSchema = z.enum(['compact', 'full']);
export type DesignPanelLayout = z.infer<typeof designPanelLayoutSchema>;

export const designTypographyModeSchema = z.enum(['inherit', 'custom']);
export type DesignTypographyMode = z.infer<typeof designTypographyModeSchema>;

export const designSchema = z.object({
  launcher: z.object({
    position: designLauncherPositionSchema,
    button_style: designLauncherButtonStyleSchema,
    icon: designLauncherIconSchema,
    brand_color: z.string(),
    corner_radius: z.number().int().min(0).max(24),
    /** Written back on save. Only meaningful when `icon === 'custom'`; every other icon value ignores it. */
    icon_upload_id: z.number().int().nonnegative(),
    /** Server-derived, read-only: posted back but the PHP whitelist drops it, so editing it does nothing. */
    icon_upload_url: z.string(),
  }),
  storefront_text: z.object({
    launcher_label: z.string().min(1),
    balance_heading: z.string().min(1),
    earn_rate_line: z.string(),
    redeem_button: z.string().min(1),
    signed_out_prompt: z.string(),
  }),
  visibility: z.object({
    scope: designVisibilityScopeSchema,
    /** Read only when `scope === 'custom_pages'`. */
    include_page_ids: z.array(z.number().int().positive()),
    hide_on_mobile: z.boolean(),
    hide_for_roles: z.array(z.string()),
  }),
  /** Pro. */
  panel: z.object({
    layout: designPanelLayoutSchema,
    show_reward_catalog: z.boolean(),
  }),
  /** Pro. */
  typography: z.object({
    mode: designTypographyModeSchema,
    font: z.string(),
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
