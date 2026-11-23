import { __, _n, sprintf } from '@wordpress/i18n';

import { formatStoredDate, formatWPCurrency } from '@/lib/helpers/format.helper';
import { formatCount, formatPoints, shortPoints } from '@/lib/helpers/points.helper';
import { flexibleDiscountSubtitle, numberConfig } from '@/lib/helpers/reward.helper';
import { hasConditions } from '@/lib/schema/condition-match.schema';
import { CatalogRewardType, Reward } from '@/lib/schema/reward.schema';

/** Reward presentation strings and derived facts; the wire carries none of this — same split as campaign-labels.ts. */

export interface RewardsScreen {
  /** Every non-archived row, built-in first, in display order. What the table renders. */
  rows: Reward[];
  /** Non-builtin, non-archived rows — what "catalog" means throughout this screen. */
  catalogRows: Reward[];
  /** Archived rows in display order: the Archived view, out of the catalog and never dragged. */
  archivedRows: Reward[];
  /** No catalog reward exists yet (2a). */
  hasNoCatalogRewards: boolean;
}

/** The one place a case is detected, from the plain list the API returns. */
export function resolveRewardsScreen(rewards: Reward[]): RewardsScreen {
  const visible = rewards.filter((reward) => reward.status !== 'archived');
  const builtin = visible.find((reward) => reward.is_builtin);
  const catalogRows = visible.filter((reward) => !reward.is_builtin);
  const rows = builtin ? [builtin, ...catalogRows] : catalogRows;

  return {
    rows,
    catalogRows,
    archivedRows: rewards.filter((reward) => reward.status === 'archived'),
    hasNoCatalogRewards: catalogRows.length === 0,
  };
}

export function typeLabel(type: Reward['type']): string {
  switch (type) {
    case 'flexible_discount':
      return __('Flexible discount', 'yayloyalty');
    case 'fixed_coupon':
      return __('Discount', 'yayloyalty');
    case 'percent_coupon':
      return __('Percentage', 'yayloyalty');
    case 'free_shipping':
      return __('Free shipping', 'yayloyalty');
    case 'free_product':
      return __('Free product', 'yayloyalty');
  }
}

/** A catalog type's starting-point title: the template card heading and the new-reward page header. */
export function templateTitle(type: CatalogRewardType): string {
  switch (type) {
    case 'fixed_coupon':
      return __('Fixed discount', 'yayloyalty');
    case 'percent_coupon':
      return __('Percentage off', 'yayloyalty');
    case 'free_shipping':
      return __('Free shipping', 'yayloyalty');
    case 'free_product':
      return __('Free product', 'yayloyalty');
  }
}

/** A new reward's default name, from its config: "$5 Off", "15% Off - Max $50"; the template title otherwise. */
export function defaultRewardName(type: CatalogRewardType, config: Reward['config']): string {
  if (type === 'fixed_coupon') {
    const amount = numberConfig(config, 'amount');

    if (amount !== null) {
      /* translators: %s: discount amount, formatted as money. */
      return sprintf(__('%s Off', 'yayloyalty'), formatWPCurrency(amount));
    }
  }

  if (type === 'percent_coupon') {
    const percent = numberConfig(config, 'percent');
    const maxDiscount = numberConfig(config, 'max_discount');

    if (percent !== null && maxDiscount !== null) {
      return sprintf(
        /* translators: 1: discount percent, 2: maximum discount, formatted as money. */
        __('%1$s%% Off - Max %2$s', 'yayloyalty'),
        String(percent),
        formatWPCurrency(maxDiscount),
      );
    }

    if (percent !== null) {
      /* translators: %s: discount percent. */
      return sprintf(__('%s%% Off', 'yayloyalty'), String(percent));
    }
  }

  return templateTitle(type);
}

/**
 * Display order of the catalog starting points, on the "Add a reward" page and the empty-state cards; the types
 * a merchant can create. Free shipping and free product are left out of this release: their existing rewards
 * still list and edit, but no new one can be created or duplicated.
 */
export const CREATABLE_REWARD_TYPES: CatalogRewardType[] = ['fixed_coupon', 'percent_coupon'];

export function isCreatableRewardType(type: string): type is CatalogRewardType {
  return (CREATABLE_REWARD_TYPES as readonly string[]).includes(type);
}

/** What a catalog type gives the customer, for the "Add a reward" cards. */
export function templateDescription(type: CatalogRewardType): string {
  switch (type) {
    case 'fixed_coupon':
      return __('A set amount off an order, applied as a coupon at checkout.', 'yayloyalty');
    case 'percent_coupon':
      return __('A percentage off an order, with an optional cap so a large order cannot drain it.', 'yayloyalty');
    case 'free_shipping':
      return __('Free shipping on an order, applied as a coupon at checkout.', 'yayloyalty');
    case 'free_product':
      return __('A product of your choice for free, added to an order with a coupon at checkout.', 'yayloyalty');
  }
}

/** The line under a reward's name: what redeeming it does, derived from type and config — there is no stored description column. */
export function rewardSubtitle(reward: Reward): string {
  if (reward.type === 'flexible_discount') {
    return flexibleDiscountSubtitle(reward.config);
  }

  return __('Applied as a coupon at checkout', 'yayloyalty');
}

/** "any" for the flexible discount — the customer picks the amount — otherwise the fixed point cost. */
export function costLabel(reward: Reward): string {
  if (reward.type === 'flexible_discount' || null === reward.cost_points) {
    return __('any', 'yayloyalty');
  }

  return shortPoints(reward.cost_points);
}

/** The built-in flexible discount's own availability phrase: its limits live in config, not conditions. */
function flexibleDiscountAvailability(config: Reward['config']): string {
  const parts: string[] = [];
  const minPoints = numberConfig(config, 'min_points');

  if (minPoints !== null) {
    parts.push(
      sprintf(
        /* translators: %s: minimum points required, formatted. */
        _n('%s pt minimum', '%s pts minimum', minPoints, 'yayloyalty'),
        formatPoints(minPoints),
      ),
    );
  }

  const maxPercent = numberConfig(config, 'max_percent_of_discountable');

  parts.push(
    maxPercent === null
      ? __('up to 100% of the order', 'yayloyalty')
      : /* translators: %s: maximum percent of the order total after other discounts, formatted. */
        sprintf(__('up to %s%% of the order', 'yayloyalty'), formatCount(maxPercent)),
  );

  return parts.join(' · ');
}

/** Every row's Availability cell: Everyone (or Selected customers), then a minimum order and a live window, whichever the reward has. */
export function availabilityLabel(reward: Reward): string {
  if (reward.type === 'flexible_discount') {
    return flexibleDiscountAvailability(reward.config);
  }

  const parts: string[] = [
    hasConditions(reward.conditions) ? __('Selected customers', 'yayloyalty') : __('Everyone', 'yayloyalty'),
  ];

  const minOrder = numberConfig(reward.config, 'min_order');

  if (minOrder !== null && minOrder > 0) {
    /* translators: %s: minimum order amount, formatted as money. */
    parts.push(sprintf(__('minimum order %s', 'yayloyalty'), formatWPCurrency(minOrder)));
  }

  if (reward.starts_at || reward.ends_at) {
    parts.push(scheduleWindowLabel(reward.starts_at, reward.ends_at));
  }

  return parts.join(' · ');
}

/**
 * starts_at/ends_at as a date range; either side may be open. Not campaign-labels' scheduleLabel: this sits
 * lowercase mid-phrase, and the range stays translatable. ends_at is exclusive, but the day it renders is the
 * last day the reward is live, so no adjustment is shown.
 */
function scheduleWindowLabel(startsAt: string | null, endsAt: string | null): string {
  if (startsAt && endsAt) {
    /* translators: 1: start date; 2: end date. */
    return sprintf(__('%1$s – %2$s', 'yayloyalty'), formatStoredDate(startsAt), formatStoredDate(endsAt));
  }

  if (startsAt) {
    /* translators: %s: the date the reward becomes available. */
    return sprintf(__('from %s', 'yayloyalty'), formatStoredDate(startsAt));
  }

  /* translators: %s: the date the reward stops being available. */
  return sprintf(__('until %s', 'yayloyalty'), formatStoredDate(endsAt as string));
}

/**
 * The line under "Rewards", in two parts so the table can put its
 * "{n} archived" link between them: the row count, then the sentence after
 * it. On the init case the sentence says nothing has been redeemed yet —
 * Redeemed stays "—" until redemption ships and defines it; its source is then
 * `redemption_summary`.
 */
export function headerSubtitle(screen: RewardsScreen): { count: string; detail: string } {
  if (screen.hasNoCatalogRewards) {
    return {
      count: __('One reward', 'yayloyalty'),
      detail: __('Nothing has been redeemed yet.', 'yayloyalty'),
    };
  }

  return {
    count: sprintf(
      /* translators: %d: number of rewards, built-in included. */
      _n('%d reward', '%d rewards', screen.rows.length, 'yayloyalty'),
      screen.rows.length,
    ),
    detail: __('Drag to reorder — this is the order customers see.', 'yayloyalty'),
  };
}
