import { z } from 'zod';

/** Mirrors ToolsImportRestController::preview(), the source of truth. */
export const importPreviewRowSchema = z.object({
  row: z.number().int(),
  status: z.enum(['ready', 'already_imported', 'error']),
  reason: z.string().nullable().optional(),
  balance: z.number().int().optional(),
  balance_after: z.number().int().optional(),
});

/** Mirrors ToolsImportRestController::apply(), the source of truth. */
export const importApplyRowSchema = z.object({
  row: z.number().int(),
  status: z.enum(['imported', 'duplicate', 'skipped', 'error']),
  reason: z.string().nullable().optional(),
});

export const importPreviewResponseSchema = z.object({ rows: z.array(importPreviewRowSchema) });
export const importApplyResponseSchema = z.object({ rows: z.array(importApplyRowSchema) });

export type ImportPreviewRow = z.infer<typeof importPreviewRowSchema>;
export type ImportApplyRow = z.infer<typeof importApplyRowSchema>;
