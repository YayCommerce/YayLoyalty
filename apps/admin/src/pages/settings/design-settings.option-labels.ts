import { __ } from '@wordpress/i18n';

import type { DesignVisibilityScope } from '@/lib/schema/design.schema';

/** Enum-value → label mappers for the Design tab's closed-choice fields. */

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
