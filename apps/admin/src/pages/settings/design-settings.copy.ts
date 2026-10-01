import { __ } from '@wordpress/i18n';

/**
 * Every label, helper and toast string for the Design settings tab.
 * Flat exported functions, not a nested object — matches
 * `customer-drawer.copy.ts`'s convention: `__()` must run after the locale
 * loads, so nothing here may be a module-scope constant.
 */

// ─── Page actions ────────────────────────────────────

export const SAVE_BUTTON_LABEL = () => __('Save changes', 'yayloyalty');
export const SAVE_SUCCESS_TOAST = () => __('Design saved.', 'yayloyalty');
export const SAVE_ERROR_FALLBACK = () => __('Could not save the design.', 'yayloyalty');
export const RESET_ERROR_FALLBACK = () => __('Could not reset the design.', 'yayloyalty');
export const RESET_CONFIRM_LABEL = () => __('Reset', 'yayloyalty');
export const CANCEL_LABEL = () => __('Cancel', 'yayloyalty');

/** Whole-tab reset, in the save-action row. Worded apart from the storefront-text reset: two destructive actions on one screen must never read alike. */
export const RESET_WHOLE_TAB_BUTTON_LABEL = () => __('Reset all Design settings', 'yayloyalty');
export const RESET_WHOLE_TAB_CONFIRM_TITLE = () => __('Reset every Design setting?', 'yayloyalty');
export const RESET_WHOLE_TAB_CONFIRM_DESCRIPTION = () =>
  __(
    'This replaces every Design setting — launcher, storefront wording, visibility and the Pro sections — with the defaults. This cannot be undone.',
    'yayloyalty',
  );
export const RESET_WHOLE_TAB_SUCCESS_TOAST = () => __('Design reset to defaults.', 'yayloyalty');

// ─── Launcher section ────────────────────────────────

export const LAUNCHER_SECTION_TITLE = () => __('Launcher', 'yayloyalty');
export const LAUNCHER_SECTION_DESCRIPTION = () =>
  __('How the floating rewards button looks and where it sits on the storefront.', 'yayloyalty');
export const LAUNCHER_POSITION_LABEL = () => __('Position', 'yayloyalty');
export const LAUNCHER_BUTTON_STYLE_LABEL = () => __('Button style', 'yayloyalty');
export const LAUNCHER_BRAND_COLOR_LABEL = () => __('Brand colour', 'yayloyalty');
export const LAUNCHER_CORNER_RADIUS_LABEL = () => __('Corner radius', 'yayloyalty');
export const LAUNCHER_CORNER_RADIUS_DESCRIPTION = () => __('0 is square, 24 is fully rounded.', 'yayloyalty');
export const LAUNCHER_ICON_LABEL = () => __('Icon', 'yayloyalty');
export const LAUNCHER_ICON_UPLOAD_LABEL = () => __('Custom icon file', 'yayloyalty');
export const LAUNCHER_ICON_UPLOAD_DESCRIPTION = () => __('Used only while Icon above is set to Custom.', 'yayloyalty');

// ─── Storefront text section ─────────────────────────

export const STOREFRONT_SECTION_TITLE = () => __('Storefront text', 'yayloyalty');
export const STOREFRONT_SECTION_DESCRIPTION = () =>
  __(
    'The wording shown on your storefront panel. The launcher label, balance heading and redeem button can’t be empty; clearing any other field stores it empty. Use Reset to defaults to bring back the original wording.',
    'yayloyalty',
  );
export const STOREFRONT_LAUNCHER_LABEL_LABEL = () => __('Launcher label', 'yayloyalty');
export const STOREFRONT_BALANCE_HEADING_LABEL = () => __('Balance heading', 'yayloyalty');
export const STOREFRONT_EARN_RATE_LINE_LABEL = () => __('Earn rate line', 'yayloyalty');
export const STOREFRONT_REDEEM_BUTTON_LABEL = () => __('Redeem button', 'yayloyalty');
export const STOREFRONT_SIGNED_OUT_PROMPT_LABEL = () => __('Signed-out prompt', 'yayloyalty');
export const STOREFRONT_REFERRAL_CARD_HEADING_LABEL = () => __('Referral card heading', 'yayloyalty');
export const STOREFRONT_TIER_PROGRESS_LINE_LABEL = () => __('Tier progress line', 'yayloyalty');

/** Storefront-text-only reset, in the section header. Wording distinct from the whole-tab reset above. */
export const STOREFRONT_RESET_BUTTON_LABEL = () => __('Reset to defaults', 'yayloyalty');
export const STOREFRONT_RESET_CONFIRM_TITLE = () => __('Reset the storefront wording?', 'yayloyalty');
export const STOREFRONT_RESET_CONFIRM_DESCRIPTION = () =>
  __(
    'This replaces the storefront text fields below with the default wording. Other Design settings, including any unsaved changes, are left alone.',
    'yayloyalty',
  );
export const STOREFRONT_REQUIRED_ERROR = () =>
  __('This text is shown on the storefront and can’t be empty.', 'yayloyalty');
export const STOREFRONT_RESET_SUCCESS_TOAST = () => __('Storefront wording reset to defaults.', 'yayloyalty');

// ─── Visibility section ──────────────────────────────

export const VISIBILITY_SECTION_TITLE = () => __('Visibility', 'yayloyalty');
export const VISIBILITY_SECTION_DESCRIPTION = () =>
  __('Where the launcher and panel appear on the storefront.', 'yayloyalty');
export const VISIBILITY_SCOPE_LABEL = () => __('Show on', 'yayloyalty');
export const VISIBILITY_INCLUDE_PAGES_LABEL = () => __('Show on these pages', 'yayloyalty');
export const VISIBILITY_INCLUDE_PAGES_PLACEHOLDER = () => __('Select pages…', 'yayloyalty');
export const VISIBILITY_INCLUDE_PAGES_SEARCH_PLACEHOLDER = () => __('Search pages…', 'yayloyalty');
export const VISIBILITY_INCLUDE_PAGES_EMPTY = () => __('No published pages match.', 'yayloyalty');
export const VISIBILITY_INCLUDE_PAGES_LIMIT_NOTE = () =>
  /* translators: %d: how many pages the picker lists at once. */
  __('Showing the first %d pages. Search to find others.', 'yayloyalty');
export const VISIBILITY_HIDE_ON_MOBILE_LABEL = () => __('Hide on mobile', 'yayloyalty');
export const VISIBILITY_HIDE_FOR_ROLES_LABEL = () => __('Hide for roles', 'yayloyalty');
export const VISIBILITY_HIDE_FOR_ROLES_PLACEHOLDER = () => __('Select roles…', 'yayloyalty');
export const VISIBILITY_HIDE_FOR_ROLES_DESCRIPTION = () =>
  __(
    'Store staff are hidden by default, so you will not see the launcher while signed in as one. Preview it in a private window.',
    'yayloyalty',
  );

// ─── Panel section (Pro) ──────────────────────────────

export const PANEL_SECTION_TITLE = () => __('Panel', 'yayloyalty');
export const PANEL_SECTION_DESCRIPTION = () => __('Layout and the panel’s optional blocks.', 'yayloyalty');
export const PANEL_LAYOUT_LABEL = () => __('Layout', 'yayloyalty');
export const PANEL_SHOW_REFERRAL_CARD_LABEL = () => __('Show referral card', 'yayloyalty');
export const PANEL_SHOW_REFERRAL_CARD_DESCRIPTION = () =>
  __('Adds the customer’s referral link and completed count.', 'yayloyalty');
export const PANEL_SHOW_PROGRESS_TO_NEXT_TIER_LABEL = () => __('Show progress to next tier', 'yayloyalty');
export const PANEL_SHOW_PROGRESS_TO_NEXT_TIER_DESCRIPTION = () =>
  __('Shows how many points a customer needs for the next tier.', 'yayloyalty');
export const PANEL_SHOW_REWARD_CATALOG_LABEL = () => __('Show reward catalog', 'yayloyalty');
export const PANEL_SHOW_REWARD_CATALOG_DESCRIPTION = () =>
  __('Lists redeemable rewards inside the panel.', 'yayloyalty');

// ─── Typography section (Pro) ─────────────────────────

export const TYPOGRAPHY_SECTION_TITLE = () => __('Typography', 'yayloyalty');
export const TYPOGRAPHY_SECTION_DESCRIPTION = () => __('The font and CSS applied to the rewards panel.', 'yayloyalty');
export const TYPOGRAPHY_MODE_LABEL = () => __('Mode', 'yayloyalty');
export const TYPOGRAPHY_FONT_LABEL = () => __('Font', 'yayloyalty');
export const TYPOGRAPHY_CUSTOM_CSS_LABEL = () => __('Custom CSS', 'yayloyalty');
export const TYPOGRAPHY_CUSTOM_CSS_DESCRIPTION = () => __('Saved, not yet applied to the storefront.', 'yayloyalty');

// ─── Tier badges section (Pro) ────────────────────────

export const TIER_SECTION_TITLE = () => __('Tier badges', 'yayloyalty');
export const TIER_SECTION_DESCRIPTION = () => __('Shown next to a customer’s tier on the storefront.', 'yayloyalty');
export const TIER_EMPTY_TITLE = () => __('No tier badges configured', 'yayloyalty');
export const TIER_EMPTY_DESCRIPTION = () =>
  __('Use Reset all Design settings to bring the three tier badges back.', 'yayloyalty');
export const TIER_BADGE_TIER_ID_LABEL = () => __('Tier ID', 'yayloyalty');
export const TIER_BADGE_TIER_ID_DESCRIPTION = () =>
  __(
    'Free-form slug — no Tiers entity exists yet, so this list isn’t configurable; only the styling below is.',
    'yayloyalty',
  );
export const TIER_BADGE_LABEL_LABEL = () => __('Label', 'yayloyalty');
export const TIER_BADGE_LABEL_DESCRIPTION = () => __('Badge label shown to the customer.', 'yayloyalty');
export const TIER_BADGE_COLOR_LABEL = () => __('Colour', 'yayloyalty');
export const TIER_BADGE_ICON_LABEL = () => __('Icon', 'yayloyalty');

// ─── Preview ─────────────────────────────────────────

export const PREVIEW_TITLE = () => __('Preview', 'yayloyalty');
export const PREVIEW_DEVICE_DESKTOP = () => __('Desktop', 'yayloyalty');
export const PREVIEW_DEVICE_MOBILE = () => __('Mobile', 'yayloyalty');
export const PREVIEW_VIEWER_MEMBER = () => __('Signed in', 'yayloyalty');
export const PREVIEW_VIEWER_GUEST = () => __('Guest', 'yayloyalty');
export const PREVIEW_COLLAPSED = () => __('Collapsed', 'yayloyalty');
export const PREVIEW_EXPANDED = () => __('Expanded', 'yayloyalty');
export const PREVIEW_FOOTNOTE = () =>
  __(
    "Preview only — the theme's own fonts and spacing apply on the storefront. Rates and rewards are your store's; the signed-in shopper is a sample.",
    'yayloyalty',
  );
export const PREVIEW_HIDDEN_ON_MOBILE = () =>
  __('Hidden on mobile. Turn off “Hide on mobile” under Visibility to show it.', 'yayloyalty');
export const PREVIEW_ERROR = () => __('The preview could not be loaded.', 'yayloyalty');
