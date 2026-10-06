import { __ } from '@wordpress/i18n';

import type {
  DesignBadgeIcon,
  DesignLauncherButtonStyle,
  DesignLauncherIcon,
  DesignLauncherPosition,
  DesignPanelLayout,
  DesignTypographyMode,
  DesignVisibilityScope,
} from '@/lib/schema/design.schema';

/** Enum-value → label mappers for the Design tab's closed-choice fields. */

export function launcherPositionLabel(position: DesignLauncherPosition): string {
  switch (position) {
    case 'bottom_right':
      return __('Bottom right', 'yayloyalty');
    case 'bottom_left':
      return __('Bottom left', 'yayloyalty');
    case 'top_right':
      return __('Top right', 'yayloyalty');
    case 'top_left':
      return __('Top left', 'yayloyalty');
  }
}

export function launcherButtonStyleLabel(style: DesignLauncherButtonStyle): string {
  switch (style) {
    case 'icon':
      return __('Icon only', 'yayloyalty');
    case 'label':
      return __('Label only', 'yayloyalty');
    case 'both':
      return __('Icon and label', 'yayloyalty');
  }
}

export function launcherIconLabel(icon: DesignLauncherIcon): string {
  switch (icon) {
    case 'default':
      return __('Default', 'yayloyalty');
    case 'circle':
      return __('Circle', 'yayloyalty');
    case 'diamond':
      return __('Diamond', 'yayloyalty');
    case 'bar':
      return __('Bar', 'yayloyalty');
    case 'ring':
      return __('Ring', 'yayloyalty');
    case 'line':
      return __('Line', 'yayloyalty');
    case 'custom':
      return __('Custom upload', 'yayloyalty');
  }
}

export function badgeIconLabel(icon: DesignBadgeIcon): string {
  switch (icon) {
    case 'ring':
      return __('Ring', 'yayloyalty');
    case 'diamond':
      return __('Diamond', 'yayloyalty');
    case 'square':
      return __('Square', 'yayloyalty');
  }
}

export function visibilityScopeLabel(scope: DesignVisibilityScope): string {
  switch (scope) {
    case 'all_pages':
      return __('All pages', 'yayloyalty');
    case 'shop_and_product':
      return __('Shop and product pages', 'yayloyalty');
    case 'custom_pages':
      return __('Only the pages I choose', 'yayloyalty');
  }
}

export function panelLayoutLabel(layout: DesignPanelLayout): string {
  return layout === 'compact' ? __('Compact', 'yayloyalty') : __('Full', 'yayloyalty');
}

export function panelLayoutDescription(layout: DesignPanelLayout): string {
  return layout === 'compact'
    ? __('Balance and redeem only', 'yayloyalty')
    : __('Tier badge, rewards, referral', 'yayloyalty');
}

export function typographyModeLabel(mode: DesignTypographyMode): string {
  return mode === 'custom' ? __('Custom', 'yayloyalty') : __('Inherit from theme', 'yayloyalty');
}

/**
 * Stands in for `typography.font: ''` ("use the theme's font"), which Radix `Select.Item` rejects
 * as a value. UI only: translate it at the form boundary and never store it.
 */
export const TYPOGRAPHY_FONT_DEFAULT_VALUE = '__default__';

/**
 * Guidance, not a whitelist: `typography.font` is a plain capped string server-side (DesignSchema),
 * so a value outside this list still round-trips. Font names stay untranslated.
 */
export function typographyFontOptions(): { value: string; label: string }[] {
  return [
    { value: TYPOGRAPHY_FONT_DEFAULT_VALUE, label: __('Default (theme font)', 'yayloyalty') },
    { value: 'Arial', label: 'Arial' },
    { value: 'Helvetica', label: 'Helvetica' },
    { value: 'Georgia', label: 'Georgia' },
    { value: 'Times New Roman', label: 'Times New Roman' },
    { value: 'Verdana', label: 'Verdana' },
    { value: 'Trebuchet MS', label: 'Trebuchet MS' },
    { value: 'Roboto', label: 'Roboto' },
    { value: 'Open Sans', label: 'Open Sans' },
    { value: 'Poppins', label: 'Poppins' },
    { value: 'Montserrat', label: 'Montserrat' },
  ];
}
