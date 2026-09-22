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
  isSingle: boolean,
  singleCustomer: AdjustPointsPreviewCustomer | undefined,
  refetch: () => Promise<{ data?: AdjustPointsPreview }>,
  isFetching: boolean,
) {
  const [baselineSnapshot, setBaselineSnapshot] = useState<AdjustPointsPreviewCustomer | null>(null);
  const [openedBalance, setOpenedBalance] = useState<number | null>(null);

  useEffect(() => {
    // `enabled` flips true→true across a close/reopen without unmounting the
    // query, so react-query serves last render's cached record synchronously
    // (isFetching true) before the staleTime:0 refetch lands. Capturing that
    // stale first render as the baseline is what makes a just-submitted
    // adjustment look like a concurrent change on reopen — wait for the
    // in-flight fetch to settle before treating a record as the baseline.
    if (baselineSnapshot === null && singleCustomer && !isFetching) {
      setBaselineSnapshot(singleCustomer);
      setOpenedBalance(singleCustomer.balance);
    }
  }, [baselineSnapshot, singleCustomer, isFetching]);

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
    /** For the submit-time re-check, which compares against a *fresh* refetch, not `singleCustomer`. */
    isStaleAgainst: (record: AdjustPointsPreviewCustomer | null) => isStaleSnapshot(baselineSnapshot, record),
    openedBalance,
    handleReload,
    reset,
  };
}
