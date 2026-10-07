import { __ } from '@wordpress/i18n';

import type { DesignLauncherPosition, DesignPanelLayout, DesignVisibilityScope } from '@/lib/schema/design.schema';

/** Enum-value → label mappers for the Design tab's closed-choice fields. */

export function launcherPositionLabel(position: DesignLauncherPosition): string {
  switch (position) {
    case 'bottom_right':
      return __('Bottom right', 'yayloyalty');
    case 'bottom_left':
      return __('Bottom left', 'yayloyalty');
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
    : __('Balance, redeem and rewards', 'yayloyalty');
}
