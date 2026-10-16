import * as v from 'valibot';

/**
 * Mirrors DesignSchema, the source of truth.
 */

export const designLauncherPositionSchema = v.picklist(['bottom_right', 'bottom_left']);
export type DesignLauncherPosition = v.InferOutput<typeof designLauncherPositionSchema>;

export const designVisibilityScopeSchema = v.picklist(['all_pages', 'shop_and_product', 'custom_pages']);
export type DesignVisibilityScope = v.InferOutput<typeof designVisibilityScopeSchema>;

export const designPanelLayoutSchema = v.picklist(['compact', 'full']);
export type DesignPanelLayout = v.InferOutput<typeof designPanelLayoutSchema>;

export const designSchema = v.object({
  launcher: v.object({
    position: designLauncherPositionSchema,
    brand_color: v.string(),
    corner_radius: v.pipe(v.number(), v.integer(), v.minValue(0), v.maxValue(24)),
  }),
  storefront_text: v.object({
    launcher_label: v.pipe(v.string(), v.minLength(1)),
    balance_heading: v.pipe(v.string(), v.minLength(1)),
    earn_rate_line: v.string(),
    redeem_button: v.pipe(v.string(), v.minLength(1)),
    signed_out_prompt: v.string(),
    product_earn_line: v.pipe(v.string(), v.minLength(1)),
    thankyou_pending_line: v.pipe(v.string(), v.minLength(1)),
    thankyou_earned_line: v.pipe(v.string(), v.minLength(1)),
  }),
  visibility: v.object({
    scope: designVisibilityScopeSchema,
    /** Read only when `scope === 'custom_pages'`. */
    include_page_ids: v.array(v.pipe(v.number(), v.integer(), v.gtValue(0))),
    hide_on_mobile: v.boolean(),
    hide_for_roles: v.array(v.string()),
    product_message: v.boolean(),
    thankyou_message: v.boolean(),
  }),
  /** Pro. */
  panel: v.object({
    layout: designPanelLayoutSchema,
    show_reward_catalog: v.boolean(),
  }),
  /** Pro. */
  typography: v.object({
    custom_css: v.string(),
  }),
  /** Stamped by DesignHelper on save; a design never saved reports the current version. */
  schema_version: v.optional(v.pipe(v.number(), v.integer())),
});

export type Design = v.InferOutput<typeof designSchema>;

/**
 * Mirrors the keys `LauncherPreview::documents()` returns, the source of truth.
 * Two whole HTML pages from the server's own launcher templates: the button alone, and the open panel.
 */
export const designPreviewSchema = v.object({
  collapsed: v.string(),
  expanded: v.string(),
});

export type DesignPreview = v.InferOutput<typeof designPreviewSchema>;
