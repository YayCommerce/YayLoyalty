import { __ } from '@wordpress/i18n';

import { Can, CAP } from '@/lib/access';
import { useCsvExport } from '@/hooks/useCsvExport';
import { Button } from '@/components/ui/button';
import { exportCustomersToCsv } from '../customers-page.export';
import { EXPORTING_LABEL } from '../customers-page.export.copy';
import type { CustomerListFilters } from '../customers-page.filters';
import { fetchAllMatchingCustomers } from '../customers-page.matching-customers';
import type { SortDirection, SortKey } from '../customers-page.sort';

/** Exports every row matching the current filters, client-side: there is no export endpoint. */
export function CustomersExportButton({
  disabled,
  queryFilters,
  sort,
}: {
  disabled: boolean;
  queryFilters: CustomerListFilters;
  sort: { by: SortKey; dir: SortDirection };
}) {
  const { isExporting, start } = useCsvExport({
    fetchRows: (signal) => fetchAllMatchingCustomers(queryFilters, sort, signal),
    download: exportCustomersToCsv,
    emptyMessage: () => __('No customers to export.', 'yayloyalty'),
    errorFallback: () => __('Could not export customers.', 'yayloyalty'),
  });

  return (
    <Can cap={CAP.VIEW} disable>
      <Button variant="outline" size="sm" disabled={disabled || isExporting} onClick={start}>
        {isExporting ? EXPORTING_LABEL() : __('Export CSV', 'yayloyalty')}
      </Button>
    </Can>
  );
}
