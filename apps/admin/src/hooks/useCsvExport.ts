import { useEffect, useRef, useState } from 'react';

import { isAbortError, toastApiError } from '@/lib/api/api';
import { toast } from '@/components/ui/toast';

/**
 * A click-started CSV export: fetches the rows, then hands them to `download`.
 * Aborts the fetch on unmount, toasts `emptyMessage` for zero rows and `errorFallback` for a failure;
 * a cancellation is silent. `download` gets the signal too, to skip saving once the owner is gone.
 */
export function useCsvExport<T>({
  fetchRows,
  download,
  emptyMessage,
  errorFallback,
}: {
  fetchRows: (signal: AbortSignal) => Promise<T[]>;
  download: (rows: T[], signal: AbortSignal) => void | Promise<void>;
  emptyMessage: () => string;
  errorFallback: () => string;
}) {
  const [isExporting, setIsExporting] = useState(false);
  const abortRef = useRef<AbortController | null>(null);

  useEffect(() => {
    return () => abortRef.current?.abort();
  }, []);

  async function start() {
    setIsExporting(true);
    const controller = new AbortController();
    abortRef.current = controller;
    try {
      const rows = await fetchRows(controller.signal);
      if (rows.length === 0) {
        toast.error(emptyMessage());
        return;
      }
      await download(rows, controller.signal);
    } catch (error) {
      if (isAbortError(error)) return;
      await toastApiError(error, errorFallback());
    } finally {
      setIsExporting(false);
    }
  }

  return { isExporting, start };
}
