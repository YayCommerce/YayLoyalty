import { useQueryClient } from '@tanstack/react-query';
import { DownloadIcon } from 'lucide-react';

import { Can, CAP } from '@/lib/access';
import { useCsvExport } from '@/hooks/useCsvExport';
import { Button } from '@/components/ui/button';
import { YayLoyaltyToolTip } from '@/components/ui/custom/tooltip';
import { EXPORTING_LABEL } from '../customers-page.export.copy';
import { fetchLedgerCampaignNames } from './customer-drawer.campaign-names';
import { ACTION_EXPORT_LEDGER, EXPORT_LEDGER_EMPTY, EXPORT_LEDGER_ERROR_FALLBACK } from './customer-drawer.copy';
import { exportLedgerToCsv, fetchFullLedger } from './customer-drawer.ledger-export';

/** Downloads the customer's whole ledger as a CSV, client-side: there is no export endpoint. */
export function CustomerDrawerExportLedgerButton({
  userId,
  size,
  iconOnly = false,
}: {
  userId: number;
  size?: 'default' | 'sm';
  /** A square download button, named by its tooltip and for screen readers. */
  iconOnly?: boolean;
}) {
  const queryClient = useQueryClient();
  const { isExporting, start } = useCsvExport({
    fetchRows: (signal) => fetchFullLedger(userId, signal),
    download: async (entries, signal) => {
      const campaignNames = await fetchLedgerCampaignNames(queryClient);
      // The drawer may have closed while the names loaded: no download after unmount.
      if (signal.aborted) return;
      exportLedgerToCsv(userId, entries, campaignNames);
    },
    emptyMessage: EXPORT_LEDGER_EMPTY,
    errorFallback: EXPORT_LEDGER_ERROR_FALLBACK,
  });

  return (
    <Can cap={CAP.VIEW} disable>
      {iconOnly ? (
        <YayLoyaltyToolTip
          content={isExporting ? EXPORTING_LABEL() : ACTION_EXPORT_LEDGER()}
          trigger={
            <Button
              variant="outline"
              size="icon"
              aria-label={isExporting ? EXPORTING_LABEL() : ACTION_EXPORT_LEDGER()}
              disabled={isExporting}
              onClick={start}
            >
              <DownloadIcon />
            </Button>
          }
        />
      ) : (
        <Button variant="outline" size={size} disabled={isExporting} onClick={start}>
          {isExporting ? EXPORTING_LABEL() : ACTION_EXPORT_LEDGER()}
        </Button>
      )}
    </Can>
  );
}
