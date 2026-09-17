import { createContext, useContext } from 'react';
import type { ResolverResult } from 'react-hook-form';

import type { Campaign, CampaignCondition, CampaignUpdateInput } from '@/lib/schema/campaign.schema';
import { validateConditionValue } from './CampaignEditorConditions';

/**
 * The campaign editor's `react-hook-form` field shape.
 *
 * Deliberately identical to `CampaignUpdateInput` (phase-01) rather than a
 * parallel shape: the write body IS the form's live values, so
 * `useForm({ resolver: zodResolver(campaignUpdateInputSchema) })`
 * (CampaignEditor.tsx) validates exactly what the merchant is editing, with
 * no second schema to keep in sync.
 *
 * `trigger` has no place here on purpose — it is immutable on edit
 * (`update_item()` never calls a trigger setter and `unset()`s it before the
 * row is written) and is rendered from the loaded `Campaign` directly via
 * `useCampaignEditorContext()`, never through form state.
 *
 * `starts_at`/`ends_at` stay the raw stored UTC strings here, unsplit —
 * matching `campaignUpdateInputSchema` exactly so its `starts_at < ends_at`
 * string-comparison refinement keeps working unmodified. The
 * `{ date, time }` pair `splitStoredDateTime`/`joinStoredDateTime`
 * (`lib/helpers/format.helper.ts`) produce is a display-only concern of the
 * Schedule section (phase-05): read the raw string via `watch()`, split for
 * its two inputs, join back on change via `setValue()`. That keeps exactly
 * one conversion point for this shape — never a bare `new Date()` on a stored
 * string (code-standards.md §4).
 */
export type CampaignFormValues = CampaignUpdateInput;

/**
 * `useCampaignQuery(id)`'s response, narrowed to the form's editable fields.
 * Feeds `useForm`'s `values` option (CampaignEditor.tsx) — chosen over a
 * `useEffect` + `reset()` so a refetch (including the post-save one) re-seeds
 * the form cleanly without a manual effect to get wrong.
 */
export function campaignToFormValues(campaign: Campaign): CampaignFormValues {
  return {
    name: campaign.name,
    status: campaign.status,
    priority: campaign.priority,
    // Deep-cloned: config/conditions are plain JSON, and form state must
    // never share array/object references with the query cache — a
    // TagsInput or useFieldArray mutating in place would otherwise corrupt
    // what useCampaignsQuery/useCampaignQuery have cached for other readers
    // (the campaigns list, etc).
    config: structuredClone(campaign.config),
    conditions: structuredClone(normalizeConditions(campaign.conditions)),
    starts_at: campaign.starts_at,
    ends_at: campaign.ends_at,
  };
}

/**
 * The write body for `useUpdateCampaignMutation`. Currently the identity
 * function — `CampaignFormValues` already matches `CampaignUpdateInput`
 * field-for-field — but kept as its own function rather than a pass-through
 * at the call site: it is the one seam a later change to the form's shape
 * (e.g. once conditions/schedule editors narrow their local state further)
 * would need, without every caller of the mutation needing to know about it.
 */
export function formValuesToUpdateInput(values: CampaignFormValues): CampaignUpdateInput {
  return values;
}

/**
 * `campaignSchema.conditions` is typed `object | array` on the wire (the
 * fixture's `dummy_row()` always emits an array, but the schema stays loose
 * to match what the REST arg validator accepts) — narrow to an array the same
 * way `CampaignTable.tsx`'s `conditionsLabel()` does, rather than trusting
 * every caller to re-check `Array.isArray`.
 */
function normalizeConditions(conditions: Campaign['conditions']): CampaignCondition[] {
  return Array.isArray(conditions) ? (conditions as CampaignCondition[]) : [];
}

/**
 * Injects per-condition-value validation into the `zodResolver`'s result —
 * wired into `useForm({ resolver })` in `CampaignEditor.tsx` as
 * `async (values, context, options) => mergeConditionErrors(await zodResolver(...)(values, context, options), values)`.
 *
 * `conditions[].value` is `z.unknown()` on `campaignUpdateInputSchema`
 * (campaign.schema.ts) — its shape genuinely varies per type, so
 * `zodResolver` never validates it. Running `validateConditionValue`
 * (CampaignEditorConditions.tsx, the same per-type checks `ConditionRow`
 * already reads errors from) here keeps this validation on RHF's normal
 * validate -> block-submit -> populate-`formState.errors` lifecycle, so it
 * fires for every save path that calls `handleSubmit` — the header's Save
 * button, "Save as draft", and `UnsavedChangeDialog`'s Save button — unlike a
 * `setError` call (wiped by the very next resolver run) or an out-of-band
 * native `<form>` `submit` listener (only reachable from an actual submit
 * event, which a dialog button click never dispatches).
 *
 * Builds the merged `errors.conditions` array via a plain `Record<number, …>`
 * rather than iterating `FieldErrors<CampaignFormValues>['conditions']`
 * directly — RHF's mapped-type errors shape for an array-of-`z.unknown()`
 * field does not type as a normal, iterable array (its own `forEach`/index
 * signature gets remapped away), so a couple of `as` casts bridge back to
 * `ResolverResult` at the edges instead.
 */
export function mergeConditionErrors(
  result: ResolverResult<CampaignFormValues>,
  values: CampaignFormValues,
): ResolverResult<CampaignFormValues> {
  const conditions = Array.isArray(values.conditions) ? values.conditions : [];
  const additions: Record<number, { type: 'manual'; message: string }> = {};

  conditions.forEach((condition, index) => {
    const message = validateConditionValue(condition);

    if (message) {
      additions[index] = { type: 'manual', message };
    }
  });

  if (Object.keys(additions).length === 0) {
    return result;
  }

  const currentErrors = result.errors as Record<string, unknown> & {
    conditions?: Array<{ value?: { type: string; message: string } } | undefined>;
  };
  const existing = Array.isArray(currentErrors.conditions) ? [...currentErrors.conditions] : [];

  Object.entries(additions).forEach(([indexKey, error]) => {
    const index = Number(indexKey);
    existing[index] = { ...existing[index], value: error };
  });

  return {
    values: {},
    errors: { ...currentErrors, conditions: existing },
  } as ResolverResult<CampaignFormValues>;
}

/* ─── editor context: the loaded campaign, outside form state ───────── */

/**
 * The loaded `Campaign`, for the fields that never live in form state —
 * `slug` and `trigger` are immutable after create (see `CampaignFormValues`'s
 * docblock above: `update_item()` never calls a trigger setter and `unset()`s
 * both before the row is written) and must always render from here, never via
 * `watch()`/`register()`.
 *
 * Declared in this support module rather than alongside `CampaignEditor.tsx`
 * (which provides it) to avoid a circular import with
 * `CampaignEditorSections.tsx` (which consumes it) — both would otherwise
 * import from each other.
 *
 * Populated once, by `CampaignEditor.tsx`, after its fetch-boundary loading/
 * error/not-found states have already returned — every consumer is
 * guaranteed a fully loaded campaign, never a half-loaded one.
 */
interface CampaignEditorContextValue {
  campaign: Campaign;
}

export const CampaignEditorContext = createContext<CampaignEditorContextValue | null>(null);

export function useCampaignEditorContext(): CampaignEditorContextValue {
  const context = useContext(CampaignEditorContext);

  if (!context) {
    throw new Error('useCampaignEditorContext() must be called within <CampaignEditor>.');
  }

  return context;
}
