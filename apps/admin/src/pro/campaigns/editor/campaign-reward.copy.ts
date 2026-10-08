import { __, _n, sprintf } from '@wordpress/i18n';

import { parseWPCurrency } from '@/lib/helpers/format.helper';
import { shortPoints } from '@/lib/helpers/points.helper';
import { ROUNDING } from '@/lib/points-math/points-math';

/**
 * What a purchase reward is set to, phrased short for the closed Advanced
 * toggle and as prose for the summary rail. One reading of the config feeds
 * both, so the two never disagree on what counts as set.
 */

/** The Advanced values that change what an order earns; 0, false and [] mean not set. */
interface PurchaseAdvancedFacts {
  includeTax: boolean;
  minSubtotal: number;
  excludeCouponOrders: boolean;
  maxPointsPerOrder: number;
  dailyCap: number;
  excludedProducts: number;
  excludedCategories: number;
}

function purchaseAdvancedFacts(config: Record<string, unknown>): PurchaseAdvancedFacts {
  const positive = (value: unknown) => (typeof value === 'number' && value > 0 ? value : 0);
  const count = (value: unknown) => (Array.isArray(value) ? value.length : 0);

  return {
    includeTax: config.include_tax === true,
    minSubtotal: positive(config.min_subtotal),
    excludeCouponOrders: config.exclude_coupon_orders === true,
    maxPointsPerOrder: positive(config.max_points_per_order),
    dailyCap: positive(config.daily_cap),
    excludedProducts: count(config.excluded_product_ids),
    excludedCategories: count(config.excluded_category_ids),
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
        parseWPCurrency(facts.minSubtotal),
      ),
    );
  }

  if (facts.excludeCouponOrders) {
    phrases.push(__('No coupon orders', 'yayloyalty'));
  }

  if (facts.includeTax) {
    phrases.push(__('Tax included', 'yayloyalty'));
  }

  if (facts.excludedProducts > 0) {
    phrases.push(productsExcluded(facts.excludedProducts));
  }

  if (facts.excludedCategories > 0) {
    phrases.push(categoriesExcluded(facts.excludedCategories));
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

  if (facts.dailyCap > 0) {
    phrases.push(
      sprintf(
        /* translators: %s: a points amount, e.g. "1,000 pts". */
        __('Capped at %s/day', 'yayloyalty'),
        shortPoints(facts.dailyCap),
      ),
    );
  }

  return phrases;
}

const ROUNDING_LABELS: Record<string, string> = {
  [ROUNDING.DOWN]: __('rounded down', 'yayloyalty'),
  [ROUNDING.NEAREST]: __('rounded to the nearest whole point', 'yayloyalty'),
  [ROUNDING.UP]: __('rounded up', 'yayloyalty'),
};

/** Base, tax and rounding as one sentence; the rail always shows it, since all three live in Advanced. */
export function purchaseCountingSentence(config: Record<string, unknown>): string {
  const base =
    config.base === 'before_discounts' ? __('before discounts', 'yayloyalty') : __('after discounts', 'yayloyalty');
  const rounding = ROUNDING_LABELS[String(config.rounding ?? ROUNDING.DOWN)] ?? ROUNDING_LABELS[ROUNDING.DOWN];

  return config.include_tax === true
    ? sprintf(
        /* translators: 1: "after discounts" or "before discounts"; 2: the rounding rule, e.g. "rounded down". */
        __('Counted on the subtotal %1$s, plus tax, %2$s.', 'yayloyalty'),
        base,
        rounding,
      )
    : sprintf(
        /* translators: 1: "after discounts" or "before discounts"; 2: the rounding rule, e.g. "rounded down". */
        __('Counted on the subtotal %1$s, %2$s.', 'yayloyalty'),
        base,
        rounding,
      );
}

/**
 * Eligibility, caps and exclusions for the rail, one sentence per group that
 * has anything set. The minimum names its measure because the engine checks
 * the whole order before discounts, not the amount counted for points.
 */
export function purchaseLimitSentences(config: Record<string, unknown>): string[] {
  const facts = purchaseAdvancedFacts(config);
  const sentences: string[] = [];
  const minimum = parseWPCurrency(facts.minSubtotal);

  if (facts.minSubtotal > 0 && facts.excludeCouponOrders) {
    sentences.push(
      sprintf(
        /* translators: %s: minimum order subtotal in the store format, e.g. "$500.00". */
        __(
          'Does not apply to orders under %s (whole order, before discounts) or to orders with a coupon.',
          'yayloyalty',
        ),
        minimum,
      ),
    );
  } else if (facts.minSubtotal > 0) {
    sentences.push(
      sprintf(
        /* translators: %s: minimum order subtotal in the store format, e.g. "$500.00". */
        __('Does not apply to orders under %s (whole order, before discounts).', 'yayloyalty'),
        minimum,
      ),
    );
  } else if (facts.excludeCouponOrders) {
    sentences.push(__('Does not apply to orders with a coupon.', 'yayloyalty'));
  }

  if (facts.maxPointsPerOrder > 0 && facts.dailyCap > 0) {
    sentences.push(
      sprintf(
        /* translators: 1: per-order cap, e.g. "500 pts"; 2: daily cap, e.g. "1,000 pts". */
        __('Capped at %1$s per order and %2$s per day.', 'yayloyalty'),
        shortPoints(facts.maxPointsPerOrder),
        shortPoints(facts.dailyCap),
      ),
    );
  } else if (facts.maxPointsPerOrder > 0) {
    sentences.push(
      sprintf(
        /* translators: %s: per-order cap, e.g. "500 pts". */
        __('Capped at %s per order.', 'yayloyalty'),
        shortPoints(facts.maxPointsPerOrder),
      ),
    );
  } else if (facts.dailyCap > 0) {
    sentences.push(
      sprintf(
        /* translators: %s: daily cap, e.g. "1,000 pts". */
        __('Capped at %s per day.', 'yayloyalty'),
        shortPoints(facts.dailyCap),
      ),
    );
  }

  if (facts.excludedProducts > 0 && facts.excludedCategories > 0) {
    sentences.push(
      sprintf(
        /* translators: 1: a count of products, e.g. "2 products"; 2: a count of categories, e.g. "1 category". */
        __('Excluded: %1$s and %2$s.', 'yayloyalty'),
        productsCount(facts.excludedProducts),
        categoriesCount(facts.excludedCategories),
      ),
    );
  } else if (facts.excludedProducts > 0) {
    sentences.push(`${productsExcluded(facts.excludedProducts)}.`);
  } else if (facts.excludedCategories > 0) {
    sentences.push(`${categoriesExcluded(facts.excludedCategories)}.`);
  }

  return sentences;
}

function productsCount(count: number): string {
  return sprintf(
    /* translators: %d: number of products. */
    _n('%d product', '%d products', count, 'yayloyalty'),
    count,
  );
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

function categoriesCount(count: number): string {
  return sprintf(
    /* translators: %d: number of categories. */
    _n('%d category', '%d categories', count, 'yayloyalty'),
    count,
  );
}
