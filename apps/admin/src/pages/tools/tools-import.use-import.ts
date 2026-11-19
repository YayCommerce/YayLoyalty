import { useEffect, useRef, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';

import { getApiErrorMessage, isAbortError } from '@/lib/api/api';
import { APPLY_CHUNK, applyImport, ImportRowInput, PREVIEW_CHUNK, previewImport } from '@/lib/api/tools.api';
import type { ImportPreviewRow } from '@/lib/schema/tools-import.schema';
import { ImportCsvRow, importIdForRows, parseImportCsv } from './tools-import.csv';
import { csvError, previewFailed, readFailed } from './tools-page.copy';

/** One file row as the preview table shows it: the CSV's values with the server's verdict. */
export type PreviewRow = ImportRowInput & Omit<ImportPreviewRow, 'row'>;

/** Mirrors the `file_name` arg's maxLength in ToolsImportRouteArgs::apply(), the source of truth. */
const FILE_NAME_MAX_LENGTH = 100;

interface Previewed {
  importId: number;
  fileName: string;
  rows: PreviewRow[];
}

export type ImportState =
  | { step: 'idle' }
  | { step: 'previewing'; done: number; total: number }
  | ({ step: 'previewed' } & Previewed)
  | ({ step: 'applying'; done: number; total: number } & Previewed)
  | { step: 'applied'; imported: number; points: number; failed: number }
  | { step: 'failed'; message: string };

/** Read → chunked preview → chunked apply, for Tools → Import balances. */
export function useToolsImport() {
  const queryClient = useQueryClient();
  const [state, setState] = useState<ImportState>({ step: 'idle' });
  const abortRef = useRef<AbortController | null>(null);

  useEffect(() => () => abortRef.current?.abort(), []);

  // Leaving mid-apply would stop the chunks; the merchant is asked first.
  useEffect(() => {
    if (state.step !== 'applying') return;

    const warn = (event: BeforeUnloadEvent) => event.preventDefault();
    window.addEventListener('beforeunload', warn);

    return () => window.removeEventListener('beforeunload', warn);
  }, [state.step]);

  async function choose(file: File) {
    abortRef.current?.abort();
    const controller = new AbortController();
    abortRef.current = controller;

    let text: string;
    try {
      text = await file.text();
    } catch {
      if (!controller.signal.aborted) setState({ step: 'failed', message: readFailed() });
      return;
    }

    // A file picked since this one started reading owns the state now.
    if (controller.signal.aborted) return;

    const parsed = parseImportCsv(text);
    if (parsed.error !== null) {
      setState({ step: 'failed', message: csvError(parsed.error) });
      return;
    }

    const importId = importIdForRows(parsed.rows);
    const toCheck = parsed.rows.filter((row) => !row.duplicate);
    const verdicts = new Map<number, ImportPreviewRow>();

    try {
      for (let i = 0; i < toCheck.length; i += PREVIEW_CHUNK) {
        setState({ step: 'previewing', done: i, total: toCheck.length });
        const results = await previewImport(importId, toInput(toCheck.slice(i, i + PREVIEW_CHUNK)), controller.signal);

        results.forEach((result) => verdicts.set(result.row, result));
      }
    } catch (error) {
      if (isAbortError(error)) return;
      setState({ step: 'failed', message: await getApiErrorMessage(error, previewFailed()) });
      return;
    }

    setState({
      step: 'previewed',
      importId,
      fileName: file.name.slice(0, FILE_NAME_MAX_LENGTH),
      rows: parsed.rows.map((row) => merge(row, verdicts)),
    });
  }

  async function apply() {
    if (state.step !== 'previewed') return;

    const { importId, fileName, rows } = state;
    const ready = rows.filter((row) => row.status === 'ready');
    let imported = 0;
    let points = 0;
    let failed = 0;

    for (let i = 0; i < ready.length; i += APPLY_CHUNK) {
      const chunk = ready.slice(i, i + APPLY_CHUNK);
      setState({ step: 'applying', importId, fileName, rows, done: i, total: ready.length });

      try {
        const results = await applyImport(importId, fileName, toInput(chunk));
        const byRow = new Map(chunk.map((row) => [row.row, row]));

        results.forEach((result) => {
          if (result.status === 'imported') {
            imported++;
            points += Number(byRow.get(result.row)?.points ?? 0);
          } else if (result.status !== 'duplicate') {
            failed++;
          }
        });
      } catch {
        // The rest are counted failed: re-uploading the same file retries exactly them, the key skips the rest.
        failed += ready.length - i;
        break;
      }
    }

    // Even a partly failed import moved real balances.
    void queryClient.invalidateQueries({ queryKey: ['customer'] });
    void queryClient.invalidateQueries({ queryKey: ['dashboard'] });
    setState({ step: 'applied', imported, points, failed });
  }

  return { state, choose, apply, reset: () => setState({ step: 'idle' }) };
}

function toInput(rows: readonly ImportRowInput[]): ImportRowInput[] {
  return rows.map(({ row, email, points, note }) => ({ row, email, points, note }));
}

function merge(csvRow: ImportCsvRow, verdicts: Map<number, ImportPreviewRow>): PreviewRow {
  const { duplicate, ...base } = csvRow;

  if (duplicate) return { ...base, status: 'error', reason: 'duplicate_email' };

  return { ...base, ...(verdicts.get(base.row) ?? { status: 'error', reason: null }) };
}
