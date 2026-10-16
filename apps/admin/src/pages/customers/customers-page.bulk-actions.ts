import { useEffect, useRef, useState } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';

import { isAbortError, toastApiError } from '@/lib/api/api';
import { useBulkAdjustPointsMutation } from '@/lib/queries/customer.queries';
import type { AdjustPointsInput, CustomerListRow } from '@/lib/schema/customer.schema';
import { useCsvExport } from '@/hooks/useCsvExport';
import { toast } from '@/components/ui/toast';
import { bulkSelectionChanged } from './components/customers-adjust-points.bulk.copy';
import { exportCustomersToCsv } from './customers-page.export';
import type { RowFilters } from './customers-page.filter-rows';
import {
  fetchAllMatchingCustomers,
  isSelectionFullyLoaded,
  resolveSelectedCustomers,
} from './customers-page.matching-customers';
import { EMPTY_SELECTION, type SelectionState } from './customers-page.selection';
import type { SortDirection, SortKey } from './customers-page.sort-rows';

/** Bulk Export and Adjust points. Both resolve the target rows through resolveSelectedRows() first. */
export function useCustomersBulkActions({
  rows,
  queryFilters,
  sort,
  selection,
  setSelection,
}: {
  /** The loaded page, for resolveSelectedRows()'s fast path. */
  rows: CustomerListRow[];
  queryFilters: RowFilters;
  sort: { by: SortKey; dir: SortDirection };
  selection: SelectionState;
  setSelection: (selection: SelectionState) => void;
}) {
  // Aborts Adjust points' multi-page fetch if the owner unmounts mid-request; useCsvExport() covers Export.
  const abortRef = useRef<AbortController | null>(null);

  useEffect(() => {
    return () => abortRef.current?.abort();
  }, []);

  async function resolveSelectedRows(signal: AbortSignal): Promise<CustomerListRow[]> {
    if (isSelectionFullyLoaded(rows, selection)) {
      return resolveSelectedCustomers(rows, selection);
    }

    const matching = await fetchAllMatchingCustomers(queryFilters, sort, signal);
    return resolveSelectedCustomers(matching, selection);
  }

  const { isExporting, start: handleExport } = useCsvExport({
    fetchRows: resolveSelectedRows,
    download: exportCustomersToCsv,
    emptyMessage: () => __('No selected customers to export.', 'yayloyalty'),
    errorFallback: () => __('Could not export the selected customers.', 'yayloyalty'),
  });

  const [isAdjustDialogOpen, setIsAdjustDialogOpen] = useState(false);
  // Covers the resolve step too: isPending alone left a window where a second click
  // fired two overlapping, non-idempotent batches.
  const [isAdjustSubmitting, setIsAdjustSubmitting] = useState(false);
  // Bumped to make the dialog clear its liability acknowledgement: a new count needs new consent.
  const [acknowledgementResetKey, setAcknowledgementResetKey] = useState(0);
  // The count a submit resolved when it disagreed with the dialog's. The dialog shows it until closed,
  // so the next submit is checked against a number the admin re-confirmed, not the stale preview.
  const [recountedCount, setRecountedCount] = useState<number | null>(null);
  const bulkAdjustMutation = useBulkAdjustPointsMutation();

  useEffect(() => {
    if (!isAdjustDialogOpen) setRecountedCount(null);
  }, [isAdjustDialogOpen]);

  /**
   * `expectedCount` is the count the admin acknowledged in the dialog.
   * resolveSelectedRows() re-resolves the matching set anyway, so no second preview call is needed to recheck it.
   */
  async function handleAdjustSubmit(input: AdjustPointsInput, expectedCount?: number) {
    setIsAdjustSubmitting(true);
    try {
      const controller = new AbortController();
      abortRef.current = controller;
      const selected = await resolveSelectedRows(controller.signal);

      if (selected.length === 0) {
        toast.error(__('No selected customers to adjust.', 'yayloyalty'));
        return;
      }

      if (selection.mode === 'all' && expectedCount !== undefined && selected.length !== expectedCount) {
        toast.error(bulkSelectionChanged(selected.length, expectedCount));
        setRecountedCount(selected.length);
        setAcknowledgementResetKey((key) => key + 1);
        return;
      }

      const { succeeded, failed, failedIds } = await bulkAdjustMutation.mutateAsync({
        userIds: selected.map((row) => row.user_id),
        input,
      });

      if (failed === 0) {
        toast.success(
          sprintf(
            /* translators: %d: number of customers whose points were adjusted. */
            _n('Adjusted points for %d customer.', 'Adjusted points for %d customers.', succeeded, 'yayloyalty'),
            succeeded,
          ),
        );
        setIsAdjustDialogOpen(false);
        setSelection(EMPTY_SELECTION);
      } else {
        // Keep the dialog open on the failed customers only. Adjustments are not idempotent,
        // so a retry must never reach the ones that succeeded.
        setSelection({ mode: 'partial', ids: new Set(failedIds) });
        setRecountedCount(null);
        setAcknowledgementResetKey((key) => key + 1);
        toast.error(
          sprintf(
            /* translators: 1: number of customers adjusted; 2: number of customers whose adjustment failed. */
            __('%1$d succeeded, %2$d failed. Only the failed customers are still selected.', 'yayloyalty'),
            succeeded,
            failed,
          ),
        );
      }
    } catch (error) {
      // An abort can only happen during resolve, so the mutation never fired.
      if (isAbortError(error)) return;
      await toastApiError(error, __('Could not adjust points for the selected customers.', 'yayloyalty'));
    } finally {
      setIsAdjustSubmitting(false);
    }
  }

  return {
    isExporting,
    handleExport,
    isAdjustDialogOpen,
    setIsAdjustDialogOpen,
    isAdjustSubmitting,
    handleAdjustSubmit,
    acknowledgementResetKey,
    recountedCount,
  };
}
