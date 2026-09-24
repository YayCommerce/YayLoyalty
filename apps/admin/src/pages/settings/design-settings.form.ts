import type { Design } from '@/lib/schema/design.schema';

/** The form's field shape, identical to Design: the whole blob is edited and posted back as-is. */
export type DesignFormValues = Design;

/**
 * The loaded design for useForm's `values`. Cloned via `structuredClone`, per
 * `campaign-editor.form.ts` — form state must never share references with
 * the query cache, or an in-place field edit would corrupt what other
 * readers (e.g. a refetch after window focus) have cached.
 */
export function designToFormValues(design: Design): DesignFormValues {
  return structuredClone(design);
}
