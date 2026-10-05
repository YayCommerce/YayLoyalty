import type { Design } from '@/lib/schema/design.schema';

/** The form's field shape, identical to Design: the whole blob is edited and posted back as-is. */
export type DesignFormValues = Design;

/** The loaded design for useForm's `values`, cloned so a field edit never mutates the query cache. */
export function designToFormValues(design: Design): DesignFormValues {
  return structuredClone(design);
}
