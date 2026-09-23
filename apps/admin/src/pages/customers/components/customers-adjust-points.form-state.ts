import type { AdjustPointsPreviewCustomer } from '@/lib/schema/customer.schema';

/** Pure derivations for the Adjust-points dialog — no React, so every branch is vitest-testable in isolation. */

export type AdjustDirection = 'add' | 'remove';

export type AdjustFormState = {
  direction: AdjustDirection;
  amount: number; // always a positive magnitude; direction carries the sign
  confirmAmount: number;
  reason: string;
  adjustLifetime: boolean; // wired into the submit payload — see the dialog's docblock
  showCustomerNote: boolean;
  customerNote: string;
  notifyByEmail: boolean;
};

export function initialAdjustFormState(): AdjustFormState {
  return {
    direction: 'add',
    amount: 0,
    confirmAmount: 0,
    reason: '',
    adjustLifetime: true,
    showCustomerNote: false,
    customerNote: '',
    notifyByEmail: false,
  };
}

/**
 * Amounts at or below this floor never trip the typo guard — a new
 * customer's lifetime is 0, and the guard must mean "$10-ish", not "1,000
 * points", so it reads the same on a 100-points-per-$1 store and a
 * 1-point-per-$1 store. Falls back to a flat points number only when the
 * store has no configured conversion rate (money can't be derived at all).
 */
export const TYPO_GUARD_FLOOR_CURRENCY_UNITS = 10;
export const TYPO_GUARD_FLOOR_POINTS_FALLBACK = 1000;
export const TYPO_GUARD_MULTIPLIER = 3;

export function typoGuardFloor(pointsPerCurrencyUnit: number): number {
  return pointsPerCurrencyUnit > 0
    ? TYPO_GUARD_FLOOR_CURRENCY_UNITS * pointsPerCurrencyUnit
    : TYPO_GUARD_FLOOR_POINTS_FALLBACK;
}

export function signedPoints(state: Pick<AdjustFormState, 'direction' | 'amount'>): number {
  return state.direction === 'remove' ? -state.amount : state.amount;
}

export function newBalance(state: Pick<AdjustFormState, 'direction' | 'amount'>, balance: number): number {
  return balance + signedPoints(state);
}

export function isTypoGuardActive(
  state: Pick<AdjustFormState, 'amount'>,
  lifetimeEarned: number,
  pointsPerCurrencyUnit: number,
): boolean {
  return state.amount > typoGuardFloor(pointsPerCurrencyUnit) && state.amount > TYPO_GUARD_MULTIPLIER * lifetimeEarned;
}

export function isConfirmSatisfied(state: Pick<AdjustFormState, 'amount' | 'confirmAmount'>): boolean {
  return state.confirmAmount === state.amount;
}

/** Points still needed before a negative balance clears, floored at 0. */
export function pointsToClearDeficit(balance: number): number {
  return Math.max(0, -balance);
}

/** What one balance contributes to store liability — `DashboardRepository::liability_points()` sums `balance > 0` only. */
export function liabilityContribution(balance: number): number {
  return Math.max(0, balance);
}

/**
 * Change in liability contribution across this adjustment. Deliberately not
 * `signedPoints()`'s raw magnitude: once either side of the adjustment is
 * negative, only the portion above 0 ever counted as liability, so a plain
 * "points moved" figure over- or under-states it (and can go negative when
 * the starting balance is already below zero — see AdjustPointsFields).
 */
export function liabilityDelta(balance: number, balanceAfter: number): number {
  return liabilityContribution(balanceAfter) - liabilityContribution(balance);
}

/**
 * The record the admin has acknowledged differs from the freshest fetch.
 * `lifetime_earned` is compared too, not just `balance` — it's the typo
 * guard's own threshold input, so a concurrent event that moves it without
 * a net balance change must still raise the banner. `user_id` guards
 * against a baseline left over from a different customer.
 */
export function isStaleSnapshot(
  baseline: AdjustPointsPreviewCustomer | null,
  latest: AdjustPointsPreviewCustomer | null,
): boolean {
  if (!baseline || !latest) return false;
  return (
    baseline.user_id !== latest.user_id ||
    baseline.balance !== latest.balance ||
    baseline.lifetime_earned !== latest.lifetime_earned
  );
}

export type SubmitBlockers = {
  lifetimeEarned: number;
  pointsPerCurrencyUnit: number;
  /** 3b: the balance moved since this dialog opened and has not been reloaded. */
  isStale?: boolean;
  /** The preview errored or returned no record: the guards below cannot run, so submit must not proceed. */
  isUnverified?: boolean;
  /** Bulk only: the liability acknowledgement checkbox. */
  needsAcknowledgement?: boolean;
  acknowledged?: boolean;
};

/**
 * Every blocking condition in one gate, built as a blockers object from the
 * first commit rather than a boolean retrofitted later — later phases only
 * ever add a flag here, never a new positional argument.
 */
export function canSubmit(state: AdjustFormState, blockers: SubmitBlockers): boolean {
  if (state.amount <= 0) return false;
  if (state.reason.trim() === '') return false;
  if (blockers.isStale) return false;
  if (blockers.isUnverified) return false;
  if (blockers.needsAcknowledgement && !blockers.acknowledged) return false;

  const guardActive = isTypoGuardActive(state, blockers.lifetimeEarned, blockers.pointsPerCurrencyUnit);
  if (guardActive && !isConfirmSatisfied(state)) return false;

  return true;
}

/** Bulk total for the `count > 1` layout — `amount * effectiveCount`, never the raw preview count (see phase 05). */
export function bulkTotalPoints(state: Pick<AdjustFormState, 'amount'>, effectiveCount: number): number {
  return state.amount * effectiveCount;
}

export type BulkLiabilityProjection =
  | { kind: 'none' }
  /** Add, with a known store liability: safe to project a "to" figure — no row leaves the positive set. */
  | { kind: 'range'; from: number; to: number }
  /** Remove, or liability unknown: `liability_points()` sums `balance > 0` only, so a "to" figure would
   *  overstate the reduction on any batch containing a balance smaller than the per-customer amount. */
  | { kind: 'delta'; delta: number };

export function bulkLiabilityProjection(
  direction: AdjustDirection,
  totalPoints: number,
  liabilityPoints: number | null,
): BulkLiabilityProjection {
  if (totalPoints === 0) return { kind: 'none' };
  if (direction === 'add' && liabilityPoints !== null) {
    return { kind: 'range', from: liabilityPoints, to: liabilityPoints + totalPoints };
  }
  return { kind: 'delta', delta: totalPoints };
}

/**
 * The bulk header/totals/acknowledgement count for "select all matching".
 * `count` (the client's `selectionCount()`) is already net of
 * `excludedCount` for 'all' mode — it is the right fallback for as long as
 * the server COUNT hasn't resolved yet, including permanently if the
 * preview errors. Only the raw server COUNT needs `excludedCount`
 * subtracted; doing it to both would under-count by `excludedCount` twice.
 */
export function bulkEffectiveCount(
  usedFilters: boolean,
  count: number,
  previewCount: number | undefined,
  excludedCount: number,
): number {
  if (!usedFilters) return count;
  return previewCount !== undefined ? Math.max(0, previewCount - excludedCount) : count;
}
