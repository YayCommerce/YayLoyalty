import { useState } from 'react';
import { toast } from 'sonner';
import { __, _n, sprintf } from '@wordpress/i18n';

import { getApiErrorMessage } from '@/lib/api/api';
import { useBulkAdjustPointsMutation } from '@/lib/queries/customer.queries';
import type { AdjustPointsInput, CustomerListRow } from '@/lib/schema/customer.schema';
import { exportCustomersToCsv } from './customers-page.export';
import type { RowFilters } from './customers-page.filter-rows';
import {
  fetchAllMatchingCustomers,
  isSelectionFullyLoaded,
  resolveSelectedCustomers,
} from './customers-page.matching-customers';
import { EMPTY_SELECTION, type SelectionState } from './customers-page.selection';
import type { SortDirection, SortKey } from './customers-page.sort-rows';

/**
 * Bulk Export + bulk Adjust points — split out of `CustomersScreen.tsx` to
 * keep that file under the project's 200-line cap. Both share one problem:
 * neither the query nor 'all'-mode selection (see `customers-page.selection.ts`)
 * necessarily holds the full list of matching rows client-side, so both
 * resolve the actual target rows the same way — `resolveSelectedRows()`
 * below, fast path and all — before doing their real work.
 */
export function useCustomersBulkActions({
  rows,
  queryFilters,
  sort,
  selection,
  setSelection,
}: {
  /** Currently loaded page — see `resolveSelectedRows()`'s fast path below. */
  rows: CustomerListRow[];
  queryFilters: RowFilters;
  sort: { by: SortKey; dir: SortDirection };
  selection: SelectionState;
  setSelection: (selection: SelectionState) => void;
}) {
  async function resolveSelectedRows(): Promise<CustomerListRow[]> {
    // Common case: every picked row is already sitting in the currently
    // loaded page (a handful of rows checked, no page navigation since) —
    // skip the round trip and resolve straight from what's already in hand
    // instead of re-fetching the whole matching set just to filter it back
    // down to the same rows.
    if (isSelectionFullyLoaded(rows, selection)) {
      return resolveSelectedCustomers(rows, selection);
    }

    const matching = await fetchAllMatchingCustomers(queryFilters, sort);
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
      toast.error(await getApiErrorMessage(error, __('Could not export the selected customers.', 'yayloyalty')));
    } finally {
      setIsExporting(false);
    }
  }

  const [isAdjustDialogOpen, setIsAdjustDialogOpen] = useState(false);
  // Covers BOTH phases of a submit — resolving the target rows (a network
  // call that can take a while on a large store) and the mutation itself —
  // so the dialog's submit button stays disabled the whole time. Relying on
  // `bulkAdjustMutation.isPending` alone left a window during the resolve
  // step where the button was still clickable, and a second click there
  // would fire two overlapping (non-idempotent, see the mutation) batches.
  const [isAdjustSubmitting, setIsAdjustSubmitting] = useState(false);
  const bulkAdjustMutation = useBulkAdjustPointsMutation();

  async function handleAdjustSubmit(input: AdjustPointsInput) {
    setIsAdjustSubmitting(true);
    try {
      const selected = await resolveSelectedRows();

      if (selected.length === 0) {
        toast.error(__('No selected customers to adjust.', 'yayloyalty'));
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
        // Partial failure — leave the dialog open and the selection intact so
        // the merchant can see the summary and decide whether to retry.
        toast.error(
          sprintf(
            __('%1$d succeeded, %2$d failed. Points ceilings or permissions may differ per customer.', 'yayloyalty'),
            succeeded,
            failed,
          ),
        );
      }
    } catch (error) {
      // Covers both resolveSelectedRows() (fetching the matching set) and the
      // mutation itself — either can fail on a network error, and both must
      // surface something rather than an unhandled rejection.
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
  };
}
