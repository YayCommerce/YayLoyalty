import { useEffect, useState } from 'react';

import { MAX_MATCHING_ROWS } from '../customers-page.matching-customers';
import { bulkEffectiveCount } from './customers-adjust-points.form-state';

/** Bulk-only derived counts and the liability acknowledgement checkbox. */
export function useBulkAdjustCounts({
  isSingle,
  count,
  previewCount,
  selectionMode,
  excludedCount,
  rowsOnPage,
  acknowledgementResetKey,
  recountedCount,
  consentKey,
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
  /** The count a mismatched submit resolved; wins over the preview until the dialog closes. */
  recountedCount: number | null;
  /** Changes with whatever the consent is about (amount, direction), so a ticked box never outlives its figure. */
  consentKey: string;
}) {
  const [acknowledged, setAcknowledged] = useState(false);

  useEffect(() => {
    setAcknowledged(false);
  }, [acknowledgementResetKey, consentKey]);

  const usedFilters = !isSingle && selectionMode === 'all';
  const effectiveCount = recountedCount ?? bulkEffectiveCount(usedFilters, count, previewCount, excludedCount);
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
