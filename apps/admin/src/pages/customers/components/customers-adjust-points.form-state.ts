import type { CustomerListRow } from '@/lib/schema/customer.schema';

/** Pure derivations for the Adjust-points dialog, with no React. */

/** The customer a single adjustment targets: the fields its guards read, from `GET /customers/{id}`. */
export type AdjustTarget = Pick<CustomerListRow, 'user_id' | 'name' | 'balance' | 'lifetime_earned'>;

export type AdjustDirection = 'add' | 'remove';

export type AdjustFormState = {
  direction: AdjustDirection;
  amount: number; // always a positive magnitude; direction carries the sign
  /** The typo guard's tick; cleared whenever amount or direction changes. */
  confirmed: boolean;
  reason: string;
  adjustLifetime: boolean;
  showCustomerNote: boolean;
  customerNote: string;
  notifyByEmail: boolean;
};

export function initialAdjustFormState(reason = ''): AdjustFormState {
  return {
    direction: 'add',
    amount: 0,
    confirmed: false,
    reason,
    adjustLifetime: true,
    showCustomerNote: false,
    customerNote: '',
    notifyByEmail: false,
  };
}

/**
 * Amounts at or below this floor never trip the typo guard, since a new customer's lifetime is 0.
 * The floor is in money so it means the same at any points rate. Without a rate it falls back to a flat points number.
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

/** Points still needed before a negative balance clears, floored at 0. */
export function pointsToClearDeficit(balance: number): number {
  return Math.max(0, -balance);
}

/** What one balance contributes to store liability — `DashboardRepository::liability_points()` sums `balance > 0` only. */
export function liabilityContribution(balance: number): number {
  return Math.max(0, balance);
}

/**
 * Change in liability contribution across this adjustment. Not `signedPoints()`: only the part of a balance
 * above 0 counts as liability, so the raw points moved misstates it once either side is negative.
 */
export function liabilityDelta(balance: number, balanceAfter: number): number {
  return liabilityContribution(balanceAfter) - liabilityContribution(balance);
}

/**
 * Whether the acknowledged record differs from the latest fetch. `lifetime_earned` is compared too,
 * because the typo guard reads it. `user_id` catches a baseline left over from another customer.
 */
export function isStaleSnapshot(baseline: AdjustTarget | null, latest: AdjustTarget | null): boolean {
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
  /** The balance moved since this dialog opened and has not been reloaded. */
  isStale?: boolean;
  /** The customer's record errored or is missing: the guards below cannot run, so submit must not proceed. */
  isUnverified?: boolean;
  /** Bulk only: the liability acknowledgement checkbox. */
  needsAcknowledgement?: boolean;
  acknowledged?: boolean;
  /** Bulk only: more rows than one bulk run can touch (`MAX_MATCHING_ROWS`). */
  isOverLimit?: boolean;
};

export type SubmitBlockerKey = 'amount' | 'reason' | 'confirm' | 'acknowledge' | 'stale' | 'unverified' | 'overLimit';

/**
 * Every rule that blocks submit, in one place; submit is enabled when none apply.
 * A new rule is a new flag on `SubmitBlockers` and a key here, not a check beside this call.
 * Only "still loading" stays with the footer: that is a wait, not a rule.
 */
export function submitBlockers(state: AdjustFormState, blockers: SubmitBlockers): SubmitBlockerKey[] {
  const keys: SubmitBlockerKey[] = [];
  if (state.amount <= 0) keys.push('amount');
  if (state.reason.trim() === '') keys.push('reason');
  if (blockers.isStale) keys.push('stale');
  if (blockers.isUnverified) keys.push('unverified');
  if (blockers.isOverLimit) keys.push('overLimit');
  if (blockers.needsAcknowledgement && !blockers.acknowledged) keys.push('acknowledge');
  if (isTypoGuardActive(state, blockers.lifetimeEarned, blockers.pointsPerCurrencyUnit) && !state.confirmed) {
    keys.push('confirm');
  }
  return keys;
}

/** Bulk total — `amount * effectiveCount`, never the raw preview count, which ignores deselected rows. */
export function bulkTotalPoints(state: Pick<AdjustFormState, 'amount'>, effectiveCount: number): number {
  return state.amount * effectiveCount;
}

export type BulkLiabilityProjection =
  | { kind: 'none' }
  /** Add, with a known store liability: a "to" figure is safe, since no row leaves the positive set. */
  | { kind: 'range'; from: number; to: number }
  /**
   * Remove, or liability unknown. Only positive balances count, so a "to" figure would overstate
   * the reduction whenever a balance is smaller than the per-customer amount.
   */
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
 * The bulk count for "select all matching". `count` is already net of `excludedCount`,
 * and is the fallback until the server COUNT resolves, or for good if the preview fails.
 * Only the server COUNT needs `excludedCount` subtracted.
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
