import { useEffect, useState } from 'react';

import { MAX_MATCHING_ROWS } from '../customers-page.matching-customers';
import { bulkEffectiveCount } from './customers-adjust-points.form-state';

/**
 * Bulk-only derived numbers + the liability acknowledgement checkbox.
 * Pulled out of the dialog shell for the same reason
 * `useAdjustPreviewBaseline` was: state machinery, not a second UI surface.
 */
export function useBulkSelection({
  isSingle,
  count,
  previewCount,
  selectionMode,
  excludedCount,
  rowsOnPage,
  acknowledgementResetKey,
}: {
  isSingle: boolean;
  /** The client-known count (exact for an explicit tick list). */
  count: number;
  /** The server's COUNT for "select all matching" — undefined while loading. */
  previewCount: number | undefined;
  selectionMode: 'partial' | 'all';
  excludedCount: number;
  rowsOnPage: number;
  /** Bumped by the bulk-actions hook after a pre-submit count mismatch, to force a fresh consent. */
  acknowledgementResetKey: number;
}) {
  const [acknowledged, setAcknowledged] = useState(false);

  useEffect(() => {
    setAcknowledged(false);
  }, [acknowledgementResetKey]);

  const usedFilters = !isSingle && selectionMode === 'all';
  const effectiveCount = bulkEffectiveCount(usedFilters, count, previewCount, excludedCount);
  const notShownOnPage = usedFilters ? Math.max(0, effectiveCount - rowsOnPage) : 0;
  const isOverLimit = !isSingle && effectiveCount > MAX_MATCHING_ROWS;

  return {
    acknowledged,
    setAcknowledged,
    resetAcknowledged: () => setAcknowledged(false),
    usedFilters,
    effectiveCount,
    notShownOnPage,
    isOverLimit,
  };
}
