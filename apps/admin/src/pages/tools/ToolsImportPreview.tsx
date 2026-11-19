import { useMemo, useState } from 'react';

import { downloadCsv, toCsv } from '@/lib/helpers/csv.helper';
import { formatSignedPoints } from '@/lib/helpers/ledger.helper';
import { formatPoints } from '@/lib/helpers/points.helper';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/components/ui/custom/confirm-dialog';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import type { PreviewRow } from './tools-import.use-import';
import * as copy from './tools-page.copy';

type Filter = 'all' | PreviewRow['status'];

/** Rendering every row of a 50,000-row file would freeze the tab; the errors CSV holds them all. */
const MAX_SHOWN = 500;

/** The import preview: the summary, the filtered rows, the errors CSV, and Import behind a confirm. */
export function ToolsImportPreview({
  fileName,
  rows,
  progress,
  onImport,
  onCancel,
}: {
  fileName: string;
  rows: PreviewRow[];
  /** Set while applying. */
  progress?: { done: number; total: number };
  onImport: () => void;
  onCancel: () => void;
}) {
  const [filter, setFilter] = useState<Filter>('all');
  const [confirming, setConfirming] = useState(false);

  const { ready, errors, imported, readyPoints } = useMemo(() => {
    const ready = rows.filter((row) => row.status === 'ready');
    const errors = rows.filter((row) => row.status === 'error');

    return {
      ready,
      errors,
      imported: rows.length - ready.length - errors.length,
      readyPoints: ready.reduce((sum, row) => sum + Number(row.points), 0),
    };
  }, [rows]);
  const shown = useMemo(() => (filter === 'all' ? rows : rows.filter((row) => row.status === filter)), [rows, filter]);

  function downloadErrors() {
    downloadCsv(
      'yayloyalty-import-errors',
      toCsv(
        [copy.columnRow(), copy.columnEmail(), copy.columnPoints(), copy.columnReason()],
        errors.map((row) => [row.row, row.email, row.points, copy.rowError(row.reason)]),
      ),
    );
  }

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <span className="font-medium break-all">{fileName}</span>
        <Button variant="outline" size="sm" onClick={onCancel} disabled={progress !== undefined}>
          {copy.chooseAnotherFile()}
        </Button>
      </div>

      <Alert variant="muted">
        <AlertDescription>{copy.summary(ready.length, readyPoints, errors.length, imported)}</AlertDescription>
      </Alert>

      <div className="flex flex-wrap items-center justify-between gap-2">
        <Segmented size="sm" value={filter} onValueChange={(value) => value && setFilter(value as Filter)}>
          <SegmentedItem value="all">{copy.filterAll()}</SegmentedItem>
          <SegmentedItem value="ready">{copy.filterReady()}</SegmentedItem>
          <SegmentedItem value="error">{copy.filterErrors()}</SegmentedItem>
          <SegmentedItem value="already_imported">{copy.filterImported()}</SegmentedItem>
        </Segmented>
        {errors.length > 0 ? (
          <Button variant="link" size="sm" onClick={downloadErrors}>
            {copy.downloadErrors()}
          </Button>
        ) : null}
      </div>

      <div className="overflow-x-auto">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>{copy.columnRow()}</TableHead>
              <TableHead>{copy.columnEmail()}</TableHead>
              <TableHead className="text-right">{copy.columnPoints()}</TableHead>
              <TableHead>{copy.columnBalance()}</TableHead>
              <TableHead>{copy.columnStatus()}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {shown.slice(0, MAX_SHOWN).map((row) => (
              <TableRow key={row.row}>
                <TableCell>{row.row}</TableCell>
                <TableCell className="break-all">{row.email}</TableCell>
                <TableCell className="text-right tabular-nums">
                  {row.status === 'error' ? '—' : formatSignedPoints(Number(row.points))}
                </TableCell>
                <TableCell className="tabular-nums">
                  {row.balance !== undefined && row.balance_after !== undefined
                    ? `${formatPoints(row.balance)} → ${formatPoints(row.balance_after)}`
                    : '—'}
                </TableCell>
                <TableCell>
                  <StatusBadge row={row} />
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
      {shown.length > MAX_SHOWN ? (
        <p className="text-muted-foreground text-sm">{copy.showingFirst(MAX_SHOWN, shown.length)}</p>
      ) : null}

      {progress ? (
        <div
          role="progressbar"
          aria-valuemin={0}
          aria-valuemax={progress.total}
          aria-valuenow={progress.done}
          className="text-sm"
        >
          {copy.importing(progress.done, progress.total)}
          <div className="bg-muted mt-2 h-2 overflow-hidden rounded-full">
            <div
              className="bg-primary h-full"
              style={{ width: `${(progress.done / Math.max(progress.total, 1)) * 100}%` }}
            />
          </div>
        </div>
      ) : (
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onCancel}>
            {copy.cancel()}
          </Button>
          <Button onClick={() => setConfirming(true)} disabled={ready.length === 0}>
            {copy.importRows(ready.length)}
          </Button>
        </div>
      )}

      <ConfirmDialog
        open={confirming}
        onOpenChange={setConfirming}
        title={copy.confirmTitle(ready.length)}
        description={copy.confirmDescription(ready.length, readyPoints)}
        confirmLabel={copy.importRows(ready.length)}
        cancelLabel={copy.cancel()}
        onConfirm={() => {
          setConfirming(false);
          onImport();
        }}
      />
    </div>
  );
}

function StatusBadge({ row }: { row: PreviewRow }) {
  if (row.status === 'ready') return <Badge variant="success-soft">{copy.statusReady()}</Badge>;
  if (row.status === 'already_imported') return <Badge variant="dimmed">{copy.statusImported()}</Badge>;

  return <Badge variant="destructive-soft">✕ {copy.rowError(row.reason)}</Badge>;
}
