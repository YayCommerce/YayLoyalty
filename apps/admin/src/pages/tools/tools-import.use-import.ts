import { useEffect, useRef, useState } from 'react';

import { isAbortError } from '@/lib/api/api';
import { APPLY_CHUNK, applyImport, ImportRowInput, PREVIEW_CHUNK, previewImport } from '@/lib/api/tools.api';
import { ImportCsvRow, importIdForRows, parseImportCsv } from './tools-import.csv';
import { csvError, previewFailed, readFailed } from './tools-page.copy';

/** One file row as the preview table shows it: the CSV's values with the server's verdict. */
export interface PreviewRow {
  row: number;
  email: string;
  points: string;
  note: string;
  status: 'ready' | 'already_imported' | 'error';
  reason?: string | null;
  balance?: number;
  balanceAfter?: number;
}

export type ImportState =
  | { step: 'idle' }
  | { step: 'previewing'; done: number; total: number }
  | { step: 'previewed'; fileName: string; rows: PreviewRow[] }
  | { step: 'applying'; fileName: string; rows: PreviewRow[]; done: number; total: number }
  | { step: 'applied'; imported: number; points: number; failed: number }
  | { step: 'failed'; message: string };

/** Read → chunked preview → chunked apply, for Tools → Import balances. */
export function useToolsImport() {
  const [state, setState] = useState<ImportState>({ step: 'idle' });
  const importIdRef = useRef(0);
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
      setState({ step: 'failed', message: readFailed() });
      return;
    }

    const parsed = parseImportCsv(text);
    if (parsed.error !== null) {
      setState({ step: 'failed', message: csvError(parsed.error) });
      return;
    }

    importIdRef.current = importIdForRows(parsed.rows);
    const toCheck = parsed.rows.filter((row) => !row.duplicate);
    const verdicts = new Map<number, Omit<PreviewRow, 'email' | 'points' | 'note'>>();

    try {
      for (let i = 0; i < toCheck.length; i += PREVIEW_CHUNK) {
        setState({ step: 'previewing', done: i, total: toCheck.length });
        const results = await previewImport(
          importIdRef.current,
          toInput(toCheck.slice(i, i + PREVIEW_CHUNK)),
          controller.signal,
        );

        results.forEach((result) =>
          verdicts.set(result.row, {
            row: result.row,
            status: result.status,
            reason: result.reason,
            balance: result.balance,
            balanceAfter: result.balance_after,
          }),
        );
      }
    } catch (error) {
      if (isAbortError(error)) return;
      setState({ step: 'failed', message: previewFailed() });
      return;
    }

    setState({ step: 'previewed', fileName: file.name, rows: parsed.rows.map((row) => merge(row, verdicts)) });
  }

  async function apply() {
    if (state.step !== 'previewed') return;

    const { fileName, rows } = state;
    const ready = rows.filter((row) => row.status === 'ready');
    let imported = 0;
    let points = 0;
    let failed = 0;

    for (let i = 0; i < ready.length; i += APPLY_CHUNK) {
      const chunk = ready.slice(i, i + APPLY_CHUNK);
      setState({ step: 'applying', fileName, rows, done: i, total: ready.length });

      try {
        const results = await applyImport(importIdRef.current, fileName, toInput(chunk));
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

    setState({ step: 'applied', imported, points, failed });
  }

  return { state, choose, apply, reset: () => setState({ step: 'idle' }) };
}

function toInput(rows: ImportCsvRow[] | PreviewRow[]): ImportRowInput[] {
  return rows.map(({ row, email, points, note }) => ({ row, email, points, note }));
}

function merge(row: ImportCsvRow, verdicts: Map<number, Omit<PreviewRow, 'email' | 'points' | 'note'>>): PreviewRow {
  const base = { row: row.row, email: row.email, points: row.points, note: row.note };

  if (row.duplicate) return { ...base, status: 'error', reason: 'duplicate_email' };

  return { ...base, ...(verdicts.get(row.row) ?? { status: 'error', reason: null }) };
}
