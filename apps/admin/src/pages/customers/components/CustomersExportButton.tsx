import { useState } from 'react';
import { toast } from 'sonner';
import { __ } from '@wordpress/i18n';

import { Can, CAP } from '@/lib/access';
import { getApiErrorMessage } from '@/lib/api/api';
import { Button } from '@/components/ui/button';
import { exportCustomersToCsv } from '../customers-page.export';
import type { RowFilters } from '../customers-page.filter-rows';
import { fetchAllMatchingCustomers } from '../customers-page.matching-customers';
import type { SortDirection, SortKey } from '../customers-page.sort-rows';

/**
 * Split out of `CustomersToolbar` so it can sit in its own header row — see
 * `CustomersFilterBar.tsx` for why (a `ml-auto` button was wrapping onto its
 * own line alongside 3-4 filter controls on a narrower admin viewport).
 *
 * Exports every row matching the current filters — not just the loaded page
 * — via `fetchAllMatchingCustomers`, since there's no server-side export
 * endpoint to delegate to.
 */
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

  async function handleExport() {
    setIsExporting(true);
    try {
      const rows = await fetchAllMatchingCustomers(queryFilters, sort);
      if (rows.length === 0) {
        toast.error(__('No customers to export.', 'yayloyalty'));
        return;
      }
      exportCustomersToCsv(rows);
    } catch (error) {
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
