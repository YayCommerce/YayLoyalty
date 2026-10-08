import type { CustomerListRow } from '@/lib/schema/customer.schema';

/** Pure derivations for the Adjust-points dialog, with no React. */

/** The customer a single adjustment targets: the fields its guards read, from `GET /customers/{id}`. */
export type AdjustTarget = Pick<CustomerListRow, 'user_id' | 'name' | 'balance'>;

export type AdjustDirection = 'add' | 'remove';

export type AdjustFormState = {
  direction: AdjustDirection;
  amount: number; // always a positive magnitude; direction carries the sign
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
    reason,
    adjustLifetime: true,
    showCustomerNote: false,
    customerNote: '',
    notifyByEmail: false,
  };
}

export function signedPoints(state: Pick<AdjustFormState, 'direction' | 'amount'>): number {
  return state.direction === 'remove' ? -state.amount : state.amount;
}

export function newBalance(state: Pick<AdjustFormState, 'direction' | 'amount'>, balance: number): number {
  return balance + signedPoints(state);
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

/** Whether the acknowledged record differs from the latest fetch. `user_id` catches a baseline left over from another customer. */
export function isStaleSnapshot(baseline: AdjustTarget | null, latest: AdjustTarget | null): boolean {
  if (!baseline || !latest) return false;
  return baseline.user_id !== latest.user_id || baseline.balance !== latest.balance;
}

export type SubmitBlockers = {
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

export type SubmitBlockerKey = 'amount' | 'reason' | 'acknowledge' | 'stale' | 'unverified' | 'overLimit';

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
