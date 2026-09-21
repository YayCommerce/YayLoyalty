import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { __, _n, sprintf } from '@wordpress/i18n';

import { getApiErrorMessage, isAbortError } from '@/lib/api/api';
import { useBulkAdjustPointsMutation } from '@/lib/queries/customer.queries';
import type { AdjustPointsInput, CustomerListRow } from '@/lib/schema/customer.schema';
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
  // Aborts a multi-page fetch if the owner unmounts mid-request.
  const abortRef = useRef<AbortController | null>(null);

  useEffect(() => {
    return () => abortRef.current?.abort();
  }, []);

  async function resolveSelectedRows(): Promise<CustomerListRow[]> {
    if (isSelectionFullyLoaded(rows, selection)) {
      return resolveSelectedCustomers(rows, selection);
    }

    const controller = new AbortController();
    abortRef.current = controller;
    const matching = await fetchAllMatchingCustomers(queryFilters, sort, controller.signal);
    return resolveSelectedCustomers(matching, selection);
  }

  const [isExporting, setIsExporting] = useState(false);

  async function handleExport() {
    setIsExporting(true);
    try {
      const selected = await resolveSelectedRows();
      if (selected.length === 0) {
        toast.error(__('No selected customers to export.', 'yayloyalty'));
        return;
      }
      exportCustomersToCsv(selected);
    } catch (error) {
      if (isAbortError(error)) return;
      toast.error(await getApiErrorMessage(error, __('Could not export the selected customers.', 'yayloyalty')));
    } finally {
      setIsExporting(false);
    }
  }

  const [isAdjustDialogOpen, setIsAdjustDialogOpen] = useState(false);
  // Covers the resolve step too: isPending alone left a window where a second click
  // fired two overlapping, non-idempotent batches.
  const [isAdjustSubmitting, setIsAdjustSubmitting] = useState(false);
  // Bumped to tell the dialog to clear its liability acknowledgement after a
  // selection-changed abort below — a fresh count deserves a fresh consent.
  const [acknowledgementResetKey, setAcknowledgementResetKey] = useState(0);
  const bulkAdjustMutation = useBulkAdjustPointsMutation();

  /**
   * `expectedCount` is the figure the dialog showed and the admin
   * acknowledged. `resolveSelectedRows()` below already re-resolves the
   * matching set fresh (it has to, to get the ids to fan out over) — for
   * "select all matching" that is at least as strong as a plain re-COUNT,
   * so this reuses it rather than firing a second, redundant preview call.
   */
  async function handleAdjustSubmit(input: AdjustPointsInput, expectedCount?: number) {
    setIsAdjustSubmitting(true);
    try {
      const selected = await resolveSelectedRows();

      if (selected.length === 0) {
        toast.error(__('No selected customers to adjust.', 'yayloyalty'));
        return;
      }

      if (selection.mode === 'all' && expectedCount !== undefined && selected.length !== expectedCount) {
        toast.error(bulkSelectionChanged(selected.length, expectedCount));
        setAcknowledgementResetKey((key) => key + 1);
        return;
      }

      const { succeeded, failed } = await bulkAdjustMutation.mutateAsync({
        userIds: selected.map((row) => row.user_id),
        input,
      });

      if (failed === 0) {
        toast.success(
          sprintf(
            _n('Adjusted points for %d customer.', 'Adjusted points for %d customers.', succeeded, 'yayloyalty'),
            succeeded,
          ),
        );
        setIsAdjustDialogOpen(false);
        setSelection(EMPTY_SELECTION);
      } else {
        // Partial failure: leave the dialog open so the merchant can decide whether to retry.
        toast.error(
          sprintf(
            __('%1$d succeeded, %2$d failed. Points ceilings or permissions may differ per customer.', 'yayloyalty'),
            succeeded,
            failed,
          ),
        );
      }
    } catch (error) {
      // An abort can only happen during resolve, so the mutation never fired.
      if (isAbortError(error)) return;
      toast.error(
        await getApiErrorMessage(error, __('Could not adjust points for the selected customers.', 'yayloyalty')),
      );
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
  };
}
