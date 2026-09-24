import { z } from 'zod';

/** Mirrors DesignSchema (DesignHelper's field-set source of truth). */

// Own closed vocabulary — not the tier badge one, see designBadgeIconSchema below.
export const designLauncherIconSchema = z.enum(['default', 'circle', 'diamond', 'bar', 'ring', 'line', 'custom']);
export type DesignLauncherIcon = z.infer<typeof designLauncherIconSchema>;

// Separate from designLauncherIconSchema by design: different UI elements, free to diverge.
export const designBadgeIconSchema = z.enum(['ring', 'diamond', 'square']);
export type DesignBadgeIcon = z.infer<typeof designBadgeIconSchema>;

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

export const designBadgeSchema = z.object({
  tier_id: z.string(),
  color: z.string(),
  icon: designBadgeIconSchema,
  label: z.string(),
});
export type DesignBadge = z.infer<typeof designBadgeSchema>;

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
    /** Pro. */
    referral_card_heading: z.string(),
    /** Pro. */
    tier_progress_line: z.string(),
  }),
  visibility: z.object({
    scope: designVisibilityScopeSchema,
    except_pages: z.array(z.string()),
    hide_on_mobile: z.boolean(),
    hide_for_roles: z.array(z.string()),
  }),
  /** Pro. */
  tier: z.object({
    badges: z.array(designBadgeSchema),
  }),
  /** Pro. */
  panel: z.object({
    layout: designPanelLayoutSchema,
    show_referral_card: z.boolean(),
    show_progress_to_next_tier: z.boolean(),
    show_reward_catalog: z.boolean(),
  }),
  /** Pro. */
  typography: z.object({
    mode: designTypographyModeSchema,
    font: z.string(),
    custom_css: z.string(),
  }),
  /** Stamped by DesignHelper on save; a blob written before versioning reads as 0. */
  schema_version: z.number().int().optional(),
});

export type Design = z.infer<typeof designSchema>;
