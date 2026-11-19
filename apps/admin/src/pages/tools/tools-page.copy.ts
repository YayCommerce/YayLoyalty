import { __, _n, sprintf } from '@wordpress/i18n';

import { formatSignedPoints } from '@/lib/helpers/ledger.helper';
import { formatCount } from '@/lib/helpers/points.helper';
import { MAX_IMPORT_ROWS, type ImportCsvError } from './tools-import.csv';

/** Tools page copy, as functions so each string is translated at render time. */

export const toolsTitle = () => __('Tools', 'yayloyalty');
export const toolsDescription = () => __('Move points in and out in bulk.', 'yayloyalty');

export const importTitle = () => __('Import balances', 'yayloyalty');
export const importDescription = () =>
  __(
    "Add points from a CSV with the columns email, points and an optional note. Customers aren't emailed.",
    'yayloyalty',
  );
export const chooseFile = () => __('Choose CSV file', 'yayloyalty');
export const chooseAnotherFile = () => __('Choose another file', 'yayloyalty');
export const downloadSample = () => __('Download a sample CSV', 'yayloyalty');
export const sampleNote = () => __('Balance from our previous program', 'yayloyalty');

export const exportTitle = () => __('Export balances', 'yayloyalty');
export const exportDescription = () => __("Every customer's balance as CSV.", 'yayloyalty');

export const adjustTitle = () => __('Adjust points in bulk', 'yayloyalty');
export const adjustDescription = () =>
  __('Select customers or a segment on Customers, then choose Adjust points.', 'yayloyalty');
export const adjustButton = () => __('Go to Customers', 'yayloyalty');

export function csvError(error: ImportCsvError): string {
  switch (error) {
    case 'missing_columns':
      return __('The first row must name the columns email and points.', 'yayloyalty');
    case 'too_many_rows':
      return sprintf(
        /* translators: %s: the most rows one file may hold. */
        __('This file has more than %s rows. Split it into smaller files.', 'yayloyalty'),
        formatCount(MAX_IMPORT_ROWS),
      );
    default:
      return __('This file has no rows to import.', 'yayloyalty');
  }
}

export const readFailed = () => __('Could not read this file.', 'yayloyalty');
export const previewFailed = () => __('Could not check the rows. Try again.', 'yayloyalty');

/* translators: 1: rows checked so far, 2: rows in the file. */
export const checking = (done: number, total: number) =>
  sprintf(__('Checking rows… %1$d of %2$d', 'yayloyalty'), done, total);
/* translators: 1: rows imported so far, 2: rows to import. */
export const importing = (done: number, total: number) =>
  sprintf(__('Importing… %1$d of %2$d', 'yayloyalty'), done, total);

export const statusReady = () => __('Ready', 'yayloyalty');
export const statusImported = () => __('Already imported', 'yayloyalty');

export function rowError(reason: string | null | undefined): string {
  switch (reason) {
    case 'unknown_email':
      return __('No customer with this email', 'yayloyalty');
    case 'invalid_points':
      return __('Points must be a whole number other than 0', 'yayloyalty');
    case 'duplicate_email':
      return __('Email appears more than once in the file', 'yayloyalty');
    case 'would_go_negative':
      return __('Would leave a negative balance', 'yayloyalty');
    default:
      return __('Could not be imported', 'yayloyalty');
  }
}

export const filterAll = () => __('All', 'yayloyalty');
export const filterReady = () => __('Ready', 'yayloyalty');
export const filterErrors = () => __('Errors', 'yayloyalty');
export const filterImported = () => __('Already imported', 'yayloyalty');
export const downloadErrors = () => __('Download errors CSV', 'yayloyalty');

export const columnRow = () => __('Row', 'yayloyalty');
export const columnEmail = () => __('Email', 'yayloyalty');
export const columnPoints = () => __('Points', 'yayloyalty');
export const columnBalance = () => __('Balance now → after', 'yayloyalty');
export const columnStatus = () => __('Status', 'yayloyalty');
export const columnReason = () => __('Reason', 'yayloyalty');

/* translators: 1: rows shown, 2: rows matching the filter. */
export const showingFirst = (shown: number, total: number) =>
  sprintf(__('Showing the first %1$d of %2$d rows.', 'yayloyalty'), shown, total);

export function summary(ready: number, points: number, errors: number, imported: number): string {
  return [
    /* translators: 1: rows ready to import, 2: their points in total, signed. */
    sprintf(
      _n('%1$d ready · %2$s points', '%1$d ready · %2$s points', ready, 'yayloyalty'),
      ready,
      formatSignedPoints(points),
    ),
    /* translators: %d: rows with an error. */
    sprintf(_n('%d error', '%d errors', errors, 'yayloyalty'), errors),
    /* translators: %d: rows already imported. */
    sprintf(__('%d already imported', 'yayloyalty'), imported),
  ].join('   ');
}

export const cancel = () => __('Cancel', 'yayloyalty');
/* translators: %d: rows to import. */
export const importRows = (count: number) => sprintf(_n('Import %d row', 'Import %d rows', count, 'yayloyalty'), count);
/* translators: %d: rows to import. */
export const confirmTitle = (count: number) =>
  sprintf(_n('Import %d row?', 'Import %d rows?', count, 'yayloyalty'), count);
export const confirmDescription = (count: number, points: number) =>
  sprintf(
    /* translators: 1: points in total, signed, 2: number of customers. */
    _n(
      "This adds %1$s points across %2$d customer and can't be undone in one step.",
      "This adds %1$s points across %2$d customers and can't be undone in one step.",
      count,
      'yayloyalty',
    ),
    formatSignedPoints(points),
    count,
  );

/* translators: 1: rows imported, 2: their points in total, signed. */
export const importDone = (count: number, points: number) =>
  sprintf(
    _n('Imported %1$d row (%2$s points).', 'Imported %1$d rows (%2$s points).', count, 'yayloyalty'),
    count,
    formatSignedPoints(points),
  );
export const importPartial = (imported: number, failed: number) =>
  sprintf(
    /* translators: 1: rows imported, 2: rows that failed. */
    __('%1$d imported, %2$d failed. Upload the same file again, unchanged, to retry the failed rows.', 'yayloyalty'),
    imported,
    failed,
  );
export const done = () => __('Done', 'yayloyalty');
