import { useEffect, useState } from 'react';

import type { AdjustPointsPreview, AdjustPointsPreviewCustomer } from '@/lib/schema/customer.schema';
import { isStaleSnapshot } from './customers-adjust-points.form-state';

/**
 * 3b's baseline bookkeeping: the record the admin has acknowledged, the
 * balance first seen (for the stale banner's header sentence), and
 * re-baselining on a successful Reload — never on the submit-time
 * re-check, which must block rather than silently accept a change.
 *
 * Pulled out of the dialog shell purely to keep that file under the repo's
 * line-count guidance; this is state machinery, not a second UI surface, so
 * it stays a hook rather than another component split.
 */
export function useAdjustPreviewBaseline(
  open: boolean,
  isSingle: boolean,
  singleCustomer: AdjustPointsPreviewCustomer | undefined,
  refetch: () => Promise<{ data?: AdjustPointsPreview }>,
  isFetching: boolean,
) {
  const [baselineSnapshot, setBaselineSnapshot] = useState<AdjustPointsPreviewCustomer | null>(null);
  const [openedBalance, setOpenedBalance] = useState<number | null>(null);

  useEffect(() => {
    // While closed the query is disabled but still serves its cached record
    // with nothing in flight — capturing that would baseline a balance from
    // before the last adjustment (or another customer). On reopen, wait for
    // the staleTime:0 refetch to settle before treating a record as the baseline.
    if (open && baselineSnapshot === null && singleCustomer && !isFetching) {
      setBaselineSnapshot(singleCustomer);
      setOpenedBalance(singleCustomer.balance);
    }
  }, [open, baselineSnapshot, singleCustomer, isFetching]);

  async function handleReload() {
    const fresh = await refetch();
    const latest = fresh.data?.customers[0];
    if (latest) setBaselineSnapshot(latest);
  }

  function reset() {
    setBaselineSnapshot(null);
    setOpenedBalance(null);
  }

  return {
    isStale: isSingle && isStaleSnapshot(baselineSnapshot, singleCustomer ?? null),
    hasBaseline: baselineSnapshot !== null,
    /** For the submit-time re-check, which compares against a *fresh* refetch, not `singleCustomer`. Fails closed without a baseline. */
    isStaleAgainst: (record: AdjustPointsPreviewCustomer | null) =>
      baselineSnapshot === null || isStaleSnapshot(baselineSnapshot, record),
    openedBalance,
    handleReload,
    reset,
  };
}
