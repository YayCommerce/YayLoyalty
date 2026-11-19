import { api } from '@/lib/api/api';
import {
  importApplyResponseSchema,
  ImportApplyRow,
  importPreviewResponseSchema,
  ImportPreviewRow,
} from '@/lib/schema/tools-import.schema';

/** One CSV row as the import routes take it. */
export interface ImportRowInput {
  row: number;
  email: string;
  points: string;
  note: string;
}

/** Mirrors ToolsImportRestController::PREVIEW_MAX_ROWS and APPLY_MAX_ROWS, the source of truth. */
export const PREVIEW_CHUNK = 500;
export const APPLY_CHUNK = 100;

/** `POST /tools/import/preview`: each row's customer and balance before and after, or why it can't be imported. */
export async function previewImport(
  importId: number,
  rows: ImportRowInput[],
  signal?: AbortSignal,
): Promise<ImportPreviewRow[]> {
  const json = { import_id: importId, rows };

  return importPreviewResponseSchema.parse(await api.post('tools/import/preview', { json, signal }).json()).rows;
}

/** `POST /tools/import/apply`: writes the rows; a row already written for this import comes back `duplicate`. */
export async function applyImport(
  importId: number,
  fileName: string,
  rows: ImportRowInput[],
): Promise<ImportApplyRow[]> {
  const json = { import_id: importId, file_name: fileName, rows };

  return importApplyResponseSchema.parse(await api.post('tools/import/apply', { json }).json()).rows;
}
