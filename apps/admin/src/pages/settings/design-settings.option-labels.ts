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

/** Launcher icon vocabulary — separate from badgeIconLabel below; the two never share a value's meaning. */
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

/** Tier badge icon vocabulary — used by phase 04's Pro tier section, kept here with its sibling. */
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
      return __('Custom pages', 'yayloyalty');
  }
}

/** Two options only — a ternary, unlike the 3+-option switches above. */
export function panelLayoutLabel(layout: DesignPanelLayout): string {
  return layout === 'compact' ? __('Compact', 'yayloyalty') : __('Full', 'yayloyalty');
}

/** Ternary, mirrors panelLayoutLabel() — one short line naming what each layout includes. */
export function panelLayoutDescription(layout: DesignPanelLayout): string {
  return layout === 'compact'
    ? __('Balance and redeem only', 'yayloyalty')
    : __('Tier badge, rewards, referral', 'yayloyalty');
}

export function typographyModeLabel(mode: DesignTypographyMode): string {
  return mode === 'custom' ? __('Custom', 'yayloyalty') : __('Inherit from theme', 'yayloyalty');
}
