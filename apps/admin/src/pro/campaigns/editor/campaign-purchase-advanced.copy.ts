import { __, _n, sprintf } from '@wordpress/i18n';

import { formatWPCurrency } from '@/lib/helpers/format.helper';
import { shortPoints } from '@/lib/helpers/points.helper';

/** What a purchase reward is set to, phrased short for the closed Advanced toggle. */

/** The Advanced values that change what an order earns; 0, false and [] mean not set. */
interface PurchaseAdvancedFacts {
  includeTax: boolean;
  minSubtotal: number;
  maxPointsPerOrder: number;
  excludedProductCount: number;
  excludedCategoryCount: number;
}

function purchaseAdvancedFacts(config: Record<string, unknown>): PurchaseAdvancedFacts {
  const positive = (value: unknown) => (typeof value === 'number' && value > 0 ? value : 0);
  const count = (value: unknown) => (Array.isArray(value) ? value.length : 0);

  return {
    includeTax: config.include_tax === true,
    minSubtotal: positive(config.min_subtotal),
    maxPointsPerOrder: positive(config.max_points_per_order),
    excludedProductCount: count(config.excluded_product_ids),
    excludedCategoryCount: count(config.excluded_category_ids),
  };
}

/** Short phrases for the closed Advanced toggle, in panel order; [] when nothing is set. */
export function purchaseAdvancedPhrases(config: Record<string, unknown>): string[] {
  const facts = purchaseAdvancedFacts(config);
  const phrases: string[] = [];

  if (facts.minSubtotal > 0) {
    phrases.push(
      sprintf(
        /* translators: %s: minimum order subtotal in the store format, e.g. "$500.00". */
        __('Min %s', 'yayloyalty'),
        formatWPCurrency(facts.minSubtotal),
      ),
    );
  }

  if (facts.includeTax) {
    phrases.push(__('Tax included', 'yayloyalty'));
  }

  if (facts.excludedProductCount > 0) {
    phrases.push(productsExcluded(facts.excludedProductCount));
  }

  if (facts.excludedCategoryCount > 0) {
    phrases.push(categoriesExcluded(facts.excludedCategoryCount));
  }

  if (facts.maxPointsPerOrder > 0) {
    phrases.push(
      sprintf(
        /* translators: %s: a points amount, e.g. "500 pts". */
        __('Capped at %s/order', 'yayloyalty'),
        shortPoints(facts.maxPointsPerOrder),
      ),
    );
  }

  return phrases;
}

/** Whole strings, not "%s excluded": "excluded" agrees with the noun in many languages. */
function productsExcluded(count: number): string {
  return sprintf(
    /* translators: %d: number of products. */
    _n('%d product excluded', '%d products excluded', count, 'yayloyalty'),
    count,
  );
}

function categoriesExcluded(count: number): string {
  return sprintf(
    /* translators: %d: number of categories. */
    _n('%d category excluded', '%d categories excluded', count, 'yayloyalty'),
    count,
  );
}
