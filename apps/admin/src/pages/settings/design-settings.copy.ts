import { __, sprintf } from '@wordpress/i18n';

/**
 * Every label, helper and toast string for the Design settings tab.
 * Functions, not constants: `__()` must run after the locale loads.
 */

// Page actions

export const SAVE_BUTTON_LABEL = () => __('Save changes', 'yayloyalty');
export const SAVE_SUCCESS_TOAST = () => __('Design saved.', 'yayloyalty');
export const SAVE_ERROR_FALLBACK = () => __('Could not save the design.', 'yayloyalty');
export const RESET_ERROR_FALLBACK = () => __('Could not reset the design.', 'yayloyalty');
export const RESET_CONFIRM_LABEL = () => __('Reset', 'yayloyalty');
export const CANCEL_LABEL = () => __('Cancel', 'yayloyalty');

/** Whole-tab reset, in the save-action row. */
export const RESET_WHOLE_TAB_BUTTON_LABEL = () => __('Reset all Design settings', 'yayloyalty');
export const RESET_WHOLE_TAB_CONFIRM_TITLE = () => __('Reset every Design setting?', 'yayloyalty');
export const RESET_WHOLE_TAB_CONFIRM_DESCRIPTION = () =>
  __(
    'This replaces every Design setting — launcher, visibility and the Pro sections — with the defaults. This cannot be undone.',
    'yayloyalty',
  );
export const RESET_WHOLE_TAB_SUCCESS_TOAST = () => __('Design reset to defaults.', 'yayloyalty');

// Launcher section

export const LAUNCHER_SECTION_TITLE = () => __('Launcher', 'yayloyalty');
export const LAUNCHER_SECTION_DESCRIPTION = () =>
  __('How the floating rewards button looks and where it sits on the storefront.', 'yayloyalty');
export const LAUNCHER_POSITION_LABEL = () => __('Position', 'yayloyalty');
export const LAUNCHER_BRAND_COLOR_LABEL = () => __('Brand colour', 'yayloyalty');
export const LAUNCHER_CORNER_RADIUS_LABEL = () => __('Corner radius', 'yayloyalty');
export const LAUNCHER_CORNER_RADIUS_DESCRIPTION = () => __('0 is square, 24 is fully rounded.', 'yayloyalty');

// Visibility section

export const VISIBILITY_SECTION_TITLE = () => __('Visibility', 'yayloyalty');
export const VISIBILITY_SECTION_DESCRIPTION = () =>
  __(
    'Where the launcher, its panel, the product-page earning line and the thank-you page points appear on the storefront.',
    'yayloyalty',
  );
export const VISIBILITY_SCOPE_LABEL = () => __('Show on', 'yayloyalty');
export const VISIBILITY_INCLUDE_PAGES_LABEL = () => __('Show on these pages', 'yayloyalty');
export const VISIBILITY_INCLUDE_PAGES_PLACEHOLDER = () => __('Select pages…', 'yayloyalty');
export const VISIBILITY_INCLUDE_PAGES_SEARCH_PLACEHOLDER = () => __('Search pages…', 'yayloyalty');
export const VISIBILITY_INCLUDE_PAGES_EMPTY = () => __('No published pages match.', 'yayloyalty');
export const VISIBILITY_INCLUDE_PAGES_LIMIT_NOTE = () =>
  /* translators: %d: how many pages the picker lists at once. */
  __('Showing the first %d pages. Search to find others.', 'yayloyalty');
export const VISIBILITY_HIDE_ON_MOBILE_LABEL = () => __('Hide on mobile', 'yayloyalty');
export const VISIBILITY_PRODUCT_MESSAGE_LABEL = () => __('Show earning line on product pages', 'yayloyalty');
export const VISIBILITY_THANKYOU_MESSAGE_LABEL = () => __('Show points on the thank-you page', 'yayloyalty');
export const VISIBILITY_HIDE_FOR_ROLES_LABEL = () => __('Hide for roles', 'yayloyalty');
export const VISIBILITY_HIDE_FOR_ROLES_PLACEHOLDER = () => __('Select roles…', 'yayloyalty');
export const VISIBILITY_HIDE_FOR_ROLES_DESCRIPTION = () =>
  __(
    'Store staff are hidden by default, so you will not see the launcher while signed in as one. Preview it in a private window.',
    'yayloyalty',
  );

// Panel section (Pro)

export const PANEL_SECTION_TITLE = () => __('Panel', 'yayloyalty');
export const PANEL_SECTION_DESCRIPTION = () => __('The panel’s optional blocks.', 'yayloyalty');
export const PANEL_SHOW_REWARD_CATALOG_LABEL = () => __('Show reward catalog', 'yayloyalty');
export const PANEL_SHOW_REWARD_CATALOG_DESCRIPTION = () =>
  __('Lists redeemable rewards inside the panel.', 'yayloyalty');

// Custom CSS section (Pro)

export const TYPOGRAPHY_SECTION_TITLE = () => __('Custom CSS', 'yayloyalty');
export const TYPOGRAPHY_SECTION_DESCRIPTION = () => __('CSS applied to the rewards launcher.', 'yayloyalty');
export const TYPOGRAPHY_CUSTOM_CSS_LABEL = () => __('Custom CSS', 'yayloyalty');
export const TYPOGRAPHY_CUSTOM_CSS_DESCRIPTION = () => __('Saved, not yet applied to the storefront.', 'yayloyalty');

// Preview

export const PREVIEW_TITLE = () => __('Preview', 'yayloyalty');
export const PREVIEW_DEVICE_DESKTOP = () => __('Desktop', 'yayloyalty');
export const PREVIEW_DEVICE_MOBILE = () => __('Mobile', 'yayloyalty');
export const PREVIEW_VIEWER_MEMBER = () => __('Signed in', 'yayloyalty');
export const PREVIEW_VIEWER_GUEST = () => __('Guest', 'yayloyalty');
export const PREVIEW_COLLAPSED = () => __('Collapsed', 'yayloyalty');
export const PREVIEW_EXPANDED = () => __('Expanded', 'yayloyalty');
export const PREVIEW_HIDDEN_ON_MOBILE = () =>
  sprintf(
    /* translators: %s: the "Hide on mobile" setting's label. */
    __('Hidden on mobile. Turn off “%s” under Visibility to show it.', 'yayloyalty'),
    VISIBILITY_HIDE_ON_MOBILE_LABEL(),
  );
export const PREVIEW_ERROR = () => __('The preview could not be loaded.', 'yayloyalty');
