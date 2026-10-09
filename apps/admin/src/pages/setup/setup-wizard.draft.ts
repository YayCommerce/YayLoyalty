import { currencyDecimals } from '@/lib/helpers/format.helper';
import { rateFromSpend, spendPairFromRate } from '@/lib/helpers/points.helper';
import type { SetupDraft, SetupLive } from '@/lib/schema/setup.schema';

/**
 * The draft a wizard opens with when none was saved: the live values, with each stored rate read back
 * as the "N points for X" pair a merchant types. spendPairFromRate() keeps that reading in one place.
 */
export function draftFromLive(live: SetupLive): SetupDraft {
  const earn = spendPairFromRate(live.purchase_rate);
  const reward = spendPairFromRate(live.reward.points_per_currency_unit);

  return {
    points_label_singular: live.points_label_singular,
    points_label_plural: live.points_label_plural,
    earn: { points: Math.max(1, Math.round(earn.points)), amount: String(earn.spend) },
    signup: { ...live.signup },
    review: { ...live.review },
    reward: {
      points: Math.max(1, Math.round(reward.points)),
      amount: String(reward.spend),
      min_points: live.reward.min_points,
      max_percent: Math.round(live.reward.max_percent_of_subtotal ?? 100),
    },
    launcher: { ...live.launcher },
  };
}

/** Points per 1 currency unit; 0 while the amount is not a number above 0 yet. */
function rate(points: number, amount: string): number {
  return Number.isFinite(points) ? (rateFromSpend(Number(amount), points) ?? 0) : 0;
}

/** Points an order of `subtotal` earns, rounded down as the built-in purchase campaign rounds. */
export function earnedOn(draft: SetupDraft, subtotal: number): number {
  return Math.floor(subtotal * rate(draft.earn.points, draft.earn.amount));
}

/** What `points` are worth off an order, rounded to the store currency's decimals. */
export function rewardWorth(draft: SetupDraft, points: number): number {
  const perUnit = rate(draft.reward.points, draft.reward.amount);
  const factor = 10 ** currencyDecimals();

  return perUnit > 0 ? Math.round((points / perUnit) * factor) / factor : 0;
}

/** The share of spend a customer earns back at these two rates, as a percent. */
export function earnBackPercent(draft: SetupDraft): number {
  const redeem = rate(draft.reward.points, draft.reward.amount);

  return redeem > 0 ? (rate(draft.earn.points, draft.earn.amount) / redeem) * 100 : 0;
}
