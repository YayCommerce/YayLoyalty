import { __, _n, sprintf } from '@wordpress/i18n';

import { formatStoredDate, parseWPCurrency } from '@/lib/helpers/format.helper';
import { formatPoints } from '@/lib/helpers/points.helper';
import { Reward } from '@/lib/schema/reward.schema';

/** Reward presentation strings and derived facts; the wire carries none of this — same split as campaign-labels.ts. */

export interface RewardsScreen {
  /** The seeded flexible-discount row. Present on every real install; absent only were the seed to somehow be missing. */
  builtin: Reward | undefined;
  /** Every non-archived row, built-in first, in display order. What the table renders. */
  rows: Reward[];
  /** Non-builtin, non-archived rows — what "catalog" means throughout this screen. */
  catalogRows: Reward[];
  /** No catalog reward exists yet (2a). */
  isInitCase: boolean;
}

/** The one place a case is detected, from the plain list the API returns. */
export function resolveRewardsScreen(rewards: Reward[]): RewardsScreen {
  const visible = rewards.filter((reward) => reward.status !== 'archived');
  const builtin = visible.find((reward) => reward.is_builtin);
  const catalogRows = visible.filter((reward) => !reward.is_builtin);
  const rows = builtin ? [builtin, ...catalogRows] : catalogRows;

  return {
    builtin,
    rows,
    catalogRows,
    isInitCase: catalogRows.length === 0,
  };
}

/** One condition entry, normalised defensively: the wire type is a loose union so a malformed row degrades to an empty list rather than a crash. */
function conditionList(conditions: Reward['conditions']): { type: string; operator: string; value: unknown }[] {
  if (!Array.isArray(conditions)) {
    return [];
  }

  return conditions.filter(
    (entry): entry is { type: string; operator: string; value: unknown } =>
      !!entry && typeof entry === 'object' && 'type' in entry,
  );
}

function findCondition(conditions: Reward['conditions'], type: string) {
  return conditionList(conditions).find((condition) => condition.type === type);
}

function numberConfig(config: Reward['config'], key: string): number | null {
  const value = config[key];

  return typeof value === 'number' ? value : null;
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
    default:
      return type;
  }
}

/** The line under a reward's name: what redeeming it does, derived from type and config — there is no stored description column. */
export function rewardSubtitle(reward: Reward): string {
  if (reward.type === 'flexible_discount') {
    return __('The customer chooses how many points to use at checkout', 'yayloyalty');
  }

  if (reward.type === 'free_product') {
    return __('Free product · claim a coupon, then order the product', 'yayloyalty');
  }

  const expiryDays = numberConfig(reward.config, 'coupon_expiry_days');

  if (expiryDays === null) {
    return __('Single-use coupon', 'yayloyalty');
  }

  return sprintf(
    /* translators: %d: number of days the coupon stays valid after being claimed. */
    __('Single-use coupon, valid %d days after claiming', 'yayloyalty'),
    expiryDays,
  );
}

/** "any" for the slider — the customer picks the amount — otherwise the fixed point cost. */
export function costLabel(reward: Reward): string {
  if (reward.type === 'flexible_discount' || null === reward.cost_points) {
    return __('any', 'yayloyalty');
  }

  /* translators: %s: point cost, formatted. */
  return sprintf(__('%s pts', 'yayloyalty'), formatPoints(reward.cost_points));
}

/** "once per customer" (redemption_count lt 1), "up to %d per customer" for a higher cap. Only condition this build renders a phrase for; unknown types are silently skipped. */
function redemptionLimitLabel(conditions: Reward['conditions']): string | null {
  const condition = findCondition(conditions, 'redemption_count');

  if (!condition || condition.operator !== 'lt' || typeof condition.value !== 'number') {
    return null;
  }

  if (condition.value <= 1) {
    return __('once per customer', 'yayloyalty');
  }

  /* translators: %d: maximum number of claims per customer. */
  return sprintf(__('up to %d per customer', 'yayloyalty'), condition.value);
}

/** The built-in slider's own availability phrase: its limits live in config, not conditions. */
function flexibleDiscountAvailability(config: Reward['config']): string {
  const parts: string[] = [];
  const minPoints = numberConfig(config, 'min_points');

  if (minPoints !== null) {
    /* translators: %s: minimum points required, formatted. */
    parts.push(sprintf(__('%s pts minimum', 'yayloyalty'), formatPoints(minPoints)));
  }

  const maxPercent = numberConfig(config, 'max_percent_of_subtotal');

  parts.push(
    maxPercent === null
      ? __('up to 100% of the order', 'yayloyalty')
      : /* translators: %s: maximum percent of the order subtotal, formatted. */
        sprintf(__('up to %s%% of the order', 'yayloyalty'), formatPoints(maxPercent)),
  );

  return parts.join(' · ');
}

/** Every row's Availability cell: audience (Everyone, or a per-customer limit), a minimum order, a redemption cap, and a live window — whichever the reward actually has. */
export function availabilityLabel(reward: Reward): string {
  if (reward.type === 'flexible_discount') {
    return flexibleDiscountAvailability(reward.config);
  }

  const parts: string[] = [redemptionLimitLabel(reward.conditions) ?? __('Everyone', 'yayloyalty')];

  const minOrder = numberConfig(reward.config, 'min_order');

  if (minOrder !== null && minOrder > 0) {
    /* translators: %s: minimum order amount, formatted as money. */
    parts.push(sprintf(__('minimum order %s', 'yayloyalty'), parseWPCurrency(minOrder)));
  }

  const maxTotal = numberConfig(reward.config, 'max_total');

  if (maxTotal !== null) {
    /* translators: %s: how many units of this reward may ever be claimed, formatted. */
    parts.push(sprintf(__('%s available', 'yayloyalty'), formatPoints(maxTotal)));
  }

  if (reward.starts_at || reward.ends_at) {
    parts.push(scheduleWindowLabel(reward.starts_at, reward.ends_at));
  }

  return parts.join(' · ');
}

/** starts_at/ends_at as a date range; either side may be open. ends_at is exclusive, but the day it renders is the last day the reward is live, so no adjustment is shown. */
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

/** The line under "Rewards": the row count plus, on the init case, that nothing has been redeemed yet — Redeemed is always "—" until the redemptions table exists. */
export function headerSubtitle(screen: RewardsScreen): string {
  if (screen.isInitCase) {
    return __('One reward. Nothing has been redeemed yet.', 'yayloyalty');
  }

  return sprintf(
    /* translators: %d: number of rewards, built-in included. */
    _n(
      '%d reward. Drag to reorder — this is the order customers see.',
      '%d rewards. Drag to reorder — this is the order customers see.',
      screen.rows.length,
      'yayloyalty',
    ),
    screen.rows.length,
  );
}
