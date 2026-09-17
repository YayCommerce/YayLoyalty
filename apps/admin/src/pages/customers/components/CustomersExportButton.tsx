import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { __ } from '@wordpress/i18n';

import { Can, CAP } from '@/lib/access';
import { getApiErrorMessage, isAbortError } from '@/lib/api/api';
import { Button } from '@/components/ui/button';
import { exportCustomersToCsv } from '../customers-page.export';
import type { RowFilters } from '../customers-page.filter-rows';
import { fetchAllMatchingCustomers } from '../customers-page.matching-customers';
import type { SortDirection, SortKey } from '../customers-page.sort-rows';

/** Exports every row matching the current filters, client-side: there is no export endpoint. */
export function CustomersExportButton({
  disabled,
  queryFilters,
  sort,
}: {
  disabled: boolean;
  queryFilters: RowFilters;
  sort: { by: SortKey; dir: SortDirection };
}) {
  const [isExporting, setIsExporting] = useState(false);
  // Aborts a multi-page fetch if this unmounts mid-export.
  const abortRef = useRef<AbortController | null>(null);

  useEffect(() => {
    return () => abortRef.current?.abort();
  }, []);

  async function handleExport() {
    setIsExporting(true);
    const controller = new AbortController();
    abortRef.current = controller;
    try {
      const rows = await fetchAllMatchingCustomers(queryFilters, sort, controller.signal);
      if (rows.length === 0) {
        toast.error(__('No customers to export.', 'yayloyalty'));
        return;
      }
      exportCustomersToCsv(rows);
    } catch (error) {
      if (isAbortError(error)) return;
      toast.error(await getApiErrorMessage(error, __('Could not export customers.', 'yayloyalty')));
    } finally {
      setIsExporting(false);
    }
  }

  return (
    <Can cap={CAP.VIEW} disable>
      <Button variant="outline" size="sm" disabled={disabled || isExporting} onClick={handleExport}>
        {isExporting ? __('Exporting…', 'yayloyalty') : __('Export CSV', 'yayloyalty')}
      </Button>
    </Can>
  );
}
