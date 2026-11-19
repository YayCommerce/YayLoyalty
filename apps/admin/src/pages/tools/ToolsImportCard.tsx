import { useRef } from 'react';

import { downloadCsv, toCsv } from '@/lib/helpers/csv.helper';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { useToolsImport } from './tools-import.use-import';
import * as copy from './tools-page.copy';
import { ToolsImportPreview } from './ToolsImportPreview';

/** UX-3 "Tools page": the Import balances card, which grows into the preview once a file is chosen. */
export function ToolsImportCard() {
  const { state, choose, apply, reset } = useToolsImport();
  const inputRef = useRef<HTMLInputElement>(null);

  function downloadSample() {
    downloadCsv(
      'yayloyalty-import-sample',
      toCsv(['email', 'points', 'note'], [['customer@example.com', 100, copy.sampleNote()]]),
    );
  }

  function pick() {
    if (inputRef.current) inputRef.current.value = '';
    inputRef.current?.click();
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>{copy.importTitle()}</CardTitle>
        <CardDescription>{copy.importDescription()}</CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <input
          ref={inputRef}
          type="file"
          accept=".csv,text/csv"
          className="sr-only"
          aria-label={copy.chooseFile()}
          onChange={(event) => {
            const file = event.target.files?.[0];
            if (file) void choose(file);
          }}
        />

        {state.step === 'previewed' || state.step === 'applying' ? (
          <ToolsImportPreview
            fileName={state.fileName}
            rows={state.rows}
            progress={state.step === 'applying' ? { done: state.done, total: state.total } : undefined}
            onImport={() => void apply()}
            onCancel={reset}
          />
        ) : null}

        {state.step === 'applied' ? (
          <>
            <Alert variant={state.failed > 0 ? 'warning' : 'outline'}>
              <AlertDescription>
                {state.failed > 0
                  ? copy.importPartial(state.imported, state.failed)
                  : copy.importDone(state.imported, state.points)}
              </AlertDescription>
            </Alert>
            <div>
              <Button onClick={reset}>{copy.done()}</Button>
            </div>
          </>
        ) : null}

        {state.step === 'idle' || state.step === 'previewing' || state.step === 'failed' ? (
          <>
            {state.step === 'failed' ? (
              <Alert destructive>
                <AlertDescription>{state.message}</AlertDescription>
              </Alert>
            ) : null}
            <div className="flex flex-wrap items-center gap-3">
              <Button onClick={pick} disabled={state.step === 'previewing'}>
                {state.step === 'previewing' ? copy.checking(state.done, state.total) : copy.chooseFile()}
              </Button>
              <Button variant="link" onClick={downloadSample}>
                {copy.downloadSample()}
              </Button>
            </div>
          </>
        ) : null}
      </CardContent>
    </Card>
  );
}
