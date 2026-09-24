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

/**
 * Enum-value → label mappers for the Design tab's closed-choice fields.
 * Split out of `design-settings.copy.ts` (flat strings) to keep both files
 * under the 200-line budget — same flat-function convention, just grouped by
 * "maps a value" instead of "is a string".
 */

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
 * Radix `Select.Item` rejects an empty-string value (reserved for "no
 * selection"), but `typography.font: ''` is a legitimate, meaningful value
 * ("use the theme's font"). This sentinel stands in for it in the UI only —
 * translate at the form boundary, never store the sentinel itself.
 */
export const TYPOGRAPHY_FONT_DEFAULT_VALUE = '__default__';

/**
 * A curated list, not the closed vocabulary `coerce()` enforces server-side —
 * `typography.font` stays a plain capped string (DesignCoercion.php), so this
 * is guidance, not a whitelist; a value saved outside this list still round-trips.
 * Font names are proper nouns and stay untranslated; only "Default" is a real string.
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
