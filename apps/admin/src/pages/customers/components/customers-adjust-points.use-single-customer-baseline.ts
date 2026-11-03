import { useEffect, useState } from 'react';

import { isStaleSnapshot, type AdjustTarget } from './customers-adjust-points.form-state';

/**
 * The single-customer baseline: the record the admin acknowledged and the balance first seen.
 * It re-baselines on a successful Reload, never on the submit-time re-check, which must block instead.
 */
export function useSingleCustomerBaseline(
  open: boolean,
  isSingle: boolean,
  singleCustomer: AdjustTarget | undefined,
  refetch: (() => Promise<{ data?: AdjustTarget; error?: unknown }>) | undefined,
  isFetching: boolean,
) {
  const [baselineSnapshot, setBaselineSnapshot] = useState<AdjustTarget | null>(null);
  const [openedBalance, setOpenedBalance] = useState<number | null>(null);
  const [isOpenReadDone, setIsOpenReadDone] = useState(false);

  // The record may already be cached (the drawer holds the same detail
  // query), so each open forces one fresh read and baselines only after it.
  useEffect(() => {
    if (!open || !isSingle || !refetch) return;

    let isCurrent = true;
    void refetch().finally(() => {
      if (isCurrent) setIsOpenReadDone(true);
    });

    return () => {
      isCurrent = false;
      setIsOpenReadDone(false);
    };
  }, [open, isSingle]);

  useEffect(() => {
    if (open && isOpenReadDone && baselineSnapshot === null && singleCustomer && !isFetching) {
      setBaselineSnapshot(singleCustomer);
      setOpenedBalance(singleCustomer.balance);
    }
  }, [open, isOpenReadDone, baselineSnapshot, singleCustomer, isFetching]);

  async function handleReload() {
    if (!refetch) return;
    const fresh = await refetch();
    if (fresh.data && !fresh.error) setBaselineSnapshot(fresh.data);
  }

  function reset() {
    setBaselineSnapshot(null);
    setOpenedBalance(null);
  }

  return {
    isStale: isSingle && isStaleSnapshot(baselineSnapshot, singleCustomer ?? null),
    hasBaseline: baselineSnapshot !== null,
    /** For the submit-time re-check, which compares against a *fresh* refetch, not `singleCustomer`. Fails closed without a baseline. */
    isStaleAgainst: (record: AdjustTarget | null) =>
      baselineSnapshot === null || isStaleSnapshot(baselineSnapshot, record),
    openedBalance,
    handleReload,
    reset,
  };
}
