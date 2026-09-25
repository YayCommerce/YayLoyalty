import { __, _n, sprintf } from '@wordpress/i18n';

import { parseWPCurrency } from '@/lib/helpers/format.helper';
import { formatPoints } from '@/lib/helpers/points.helper';
import { ROUNDING } from '@/lib/points-math/points-math';
import { Campaign, resolvePurchaseMode, resolvePurchaseMultiplier } from '@/lib/schema/campaign.schema';
import { givesLabel } from '../campaign-labels';

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

/** Short phrases for the closed Advanced toggle, in field order; [] when nothing is set. */
export function purchaseAdvancedPhrases(config: Record<string, unknown>): string[] {
  const facts = purchaseAdvancedFacts(config);
  const phrases: string[] = [];

  if (facts.includeTax) {
    phrases.push(__('Tax included', 'yayloyalty'));
  }

  if (facts.minSubtotal > 0) {
    /* translators: %s: minimum order subtotal in the store format, e.g. "$500.00". */
    phrases.push(sprintf(__('Min %s', 'yayloyalty'), parseWPCurrency(facts.minSubtotal)));
  }

  if (facts.excludeCouponOrders) {
    phrases.push(__('No coupon orders', 'yayloyalty'));
  }

  if (facts.maxPointsPerOrder > 0) {
    /* translators: %s: a points amount, e.g. "500 pts". */
    phrases.push(sprintf(__('Capped at %s/order', 'yayloyalty'), shortPoints(facts.maxPointsPerOrder)));
  }

  if (facts.dailyCap > 0) {
    /* translators: %s: a points amount, e.g. "1,000 pts". */
    phrases.push(sprintf(__('Capped at %s/day', 'yayloyalty'), shortPoints(facts.dailyCap)));
  }

  if (facts.excludedProducts > 0) {
    phrases.push(productsExcluded(facts.excludedProducts));
  }

  if (facts.excludedCategories > 0) {
    phrases.push(categoriesExcluded(facts.excludedCategories));
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
    /* translators: %s: per-order cap, e.g. "500 pts". */
    sentences.push(sprintf(__('Capped at %s per order.', 'yayloyalty'), shortPoints(facts.maxPointsPerOrder)));
  } else if (facts.dailyCap > 0) {
    /* translators: %s: daily cap, e.g. "1,000 pts". */
    sentences.push(sprintf(__('Capped at %s per day.', 'yayloyalty'), shortPoints(facts.dailyCap)));
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

/**
 * The rate an order earns at. A multiplier reads the default campaign's rate,
 * passed in so this stays pure; pass undefined when that campaign is missing
 * or not active, since the engine then skips the multiplier entirely.
 */
export function effectiveRateSentence(config: Record<string, unknown>, defaultRate: number | undefined): string {
  if (resolvePurchaseMode(config) !== 'multiplier') {
    /* translators: %s: the rate, e.g. "1 pt per $1.00". */
    return sprintf(__('Earns %s.', 'yayloyalty'), givesLabel('purchase', config));
  }

  const multiplier = resolvePurchaseMultiplier(config);

  if (defaultRate === undefined) {
    return sprintf(
      /* translators: %s: the multiplier, e.g. "2". */
      __(
        'Multiplies the default purchase campaign’s rate by ×%s, but earns nothing until that campaign is active.',
        'yayloyalty',
      ),
      String(multiplier),
    );
  }

  return sprintf(
    /* translators: 1: the resulting rate, e.g. "2 pts per $1.00"; 2: the multiplier, e.g. "2"; 3: the default campaign's points per currency unit, e.g. "1". */
    __('Earns %1$s, ×%2$s the default rate of %3$s.', 'yayloyalty'),
    givesLabel('purchase', { points_per_unit: multipliedRate(multiplier, defaultRate) }),
    String(multiplier),
    String(defaultRate),
  );
}

/** Whether the default purchase campaign can lend its rate; mirrors PurchaseHandler::is_eligible, which skips a multiplier otherwise. */
export function isDefaultPurchaseActive(campaign: Campaign | undefined): boolean {
  return campaign?.status === 'active';
}

/** The rate a multiplier multiplies; undefined when the default is missing, not active, or has no rate. */
export function defaultPurchaseRate(campaign: Campaign | undefined): number | undefined {
  const rate = campaign?.config.points_per_unit;

  return isDefaultPurchaseActive(campaign) && typeof rate === 'number' ? rate : undefined;
}

/** A multiplier campaign's effective rate. Two 2-decimal inputs give at most 4 decimals; toFixed drops float noise such as 3.3000000000000003. */
export function multipliedRate(multiplier: number, defaultRate: number): number {
  return Number((multiplier * defaultRate).toFixed(4));
}

function shortPoints(points: number): string {
  /* translators: %s: a points amount, formatted. Abbreviated: this reads in tight UI. */
  return sprintf(_n('%s pt', '%s pts', points, 'yayloyalty'), formatPoints(points));
}

function productsCount(count: number): string {
  /* translators: %d: number of products. */
  return sprintf(_n('%d product', '%d products', count, 'yayloyalty'), count);
}

/** Whole strings, not "%s excluded": "excluded" agrees with the noun in many languages. */
function productsExcluded(count: number): string {
  /* translators: %d: number of products. */
  return sprintf(_n('%d product excluded', '%d products excluded', count, 'yayloyalty'), count);
}

function categoriesExcluded(count: number): string {
  /* translators: %d: number of categories. */
  return sprintf(_n('%d category excluded', '%d categories excluded', count, 'yayloyalty'), count);
}

function categoriesCount(count: number): string {
  /* translators: %d: number of categories. */
  return sprintf(_n('%d category', '%d categories', count, 'yayloyalty'), count);
}
