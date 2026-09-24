import { createContext, useContext } from 'react';
import { zodResolver } from '@hookform/resolvers/zod';
import type { Resolver, ResolverResult } from 'react-hook-form';
import { __, sprintf } from '@wordpress/i18n';

import {
  Campaign,
  CampaignCondition,
  CampaignCreateInput,
  CampaignTrigger,
  CampaignUpdateInput,
  campaignUpdateInputSchema,
  DEFAULT_CAMPAIGN_CONFIG,
  LITE_CAMPAIGN_TRIGGERS,
  LiteCampaignTrigger,
} from '@/lib/schema/campaign.schema';
import { validateConditionValue } from './CampaignEditorConditions';

/**
 * The form's field shape, identical to CampaignUpdateInput in both create and
 * edit mode. No `trigger`: on edit it is immutable and renders from the
 * loaded Campaign; on create it lives outside RHF, as CampaignEditorContext's
 * own `trigger` + `onTriggerChange`, because changing it also resets `config`
 * and `conditions` — a plain form field can't drive that from the section
 * that owns it. starts_at/ends_at stay raw UTC strings so the schema's
 * string-comparison refinement holds; the Schedule section converts at its
 * inputs only.
 */
export type CampaignFormValues = CampaignUpdateInput;

/** The loaded campaign narrowed to the editable fields, for useForm's `values`. */
export function campaignToFormValues(campaign: Campaign): CampaignFormValues {
  return {
    name: campaign.name,
    status: campaign.status,
    priority: campaign.priority,
    // Cloned: form state must never share references with the query cache, or an in-place
    // edit corrupts what other readers have cached.
    config: structuredClone(campaign.config),
    conditions: structuredClone(normalizeConditions(campaign.conditions)),
    starts_at: campaign.starts_at,
    ends_at: campaign.ends_at,
  };
}

/** Identity today; the one seam if the form's shape ever diverges from the write body. */
export function formValuesToUpdateInput(values: CampaignFormValues): CampaignUpdateInput {
  return values;
}

/** POST campaigns' body: form values plus the trigger picked in the chooser/§2, carried outside form state. */
export function formValuesToCreateInput(values: CampaignFormValues, trigger: LiteCampaignTrigger): CampaignCreateInput {
  return { ...values, trigger };
}

/**
 * A template's or a source campaign's fields shaped for the create form.
 * `trigger` is separate because it drives config's reset, not a form field
 * — see CampaignFormValues.
 */
export interface CampaignCreateSeed {
  trigger: LiteCampaignTrigger;
  values: CampaignFormValues;
}

/**
 * Duplicate's seed: every field copied from the source except id/slug — name
 * gets " (copy)" and status resets to draft, since nothing is live until
 * Save (matching every template). A source outside the three Lite triggers
 * falls back to purchase with default config, rather than sending a config
 * shape that trigger can't accept — not reachable today (nothing writes a
 * Pro-trigger campaign yet), kept as a safe default rather than an
 * assumption.
 */
export function duplicateToFormValues(source: Campaign): CampaignCreateSeed {
  const isLite = (LITE_CAMPAIGN_TRIGGERS as readonly string[]).includes(source.trigger);
  const trigger: LiteCampaignTrigger = isLite ? (source.trigger as LiteCampaignTrigger) : 'purchase';

  return {
    trigger,
    values: {
      /* translators: %s: the name of the campaign being duplicated. */
      name: sprintf(__('%s (copy)', 'yayloyalty'), source.name),
      status: 'draft',
      priority: source.priority,
      config: structuredClone(isLite ? source.config : DEFAULT_CAMPAIGN_CONFIG.purchase),
      conditions: structuredClone(isLite ? normalizeConditions(source.conditions) : []),
      starts_at: source.starts_at,
      ends_at: source.ends_at,
    },
  };
}

/**
 * Placeholder for the editor context's `campaign` in create mode, since
 * nothing is persisted yet. `id` is Number.MAX_SAFE_INTEGER rather than 0:
 * CampaignEditorPriority's ladder breaks priority ties by id, newest wins,
 * and this draft — once saved — gets a higher id than every existing
 * campaign, so the preview should already show it winning ties today.
 */
export function synthesizeDraftCampaign(): Campaign {
  const now = new Date().toISOString().slice(0, 19).replace('T', ' ');

  return {
    id: Number.MAX_SAFE_INTEGER,
    name: '',
    slug: '',
    trigger: 'purchase',
    status: 'draft',
    priority: 0,
    config: {},
    conditions: [],
    starts_at: null,
    ends_at: null,
    is_builtin: false,
    created_by: null,
    created_at: now,
    updated_at: now,
    points_issued: 0,
    is_deletable: false,
  };
}

/** The wire type is object | array; narrow to an array once here. */
function normalizeConditions(conditions: Campaign['conditions']): CampaignCondition[] {
  return Array.isArray(conditions) ? (conditions as CampaignCondition[]) : [];
}

/**
 * Adds per-condition value errors to the zodResolver result. `value` is
 * z.unknown(), so zod never checks it; running validateConditionValue in the
 * resolver keeps it on RHF's normal lifecycle for every save path, unlike
 * setError (wiped by the next resolver run) or a native submit listener
 * (never fired by a dialog button). Builds the array through a plain Record
 * because RHF's mapped error type for an array of unknown does not iterate.
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

/** One resolver for both editor modes: the fields it validates (name/status/priority/config/conditions/dates) are identical either way — only `trigger`, which it never sees, differs. */
export const campaignFormResolver: Resolver<CampaignFormValues> = async (values, context, options) => {
  const result = await zodResolver(campaignUpdateInputSchema)(values, context, options);
  return mergeConditionErrors(result, values);
};

/* ─── editor context: the loaded campaign, outside form state ───────── */

/**
 * The loaded Campaign for the fields outside form state: slug and id are
 * immutable after create and render from here, never via watch(). Declared
 * here rather than in CampaignEditor.tsx to avoid a circular import with the
 * sections. Provided only after the fetch boundary (edit) or template pick
 * (create) has resolved, so every consumer gets a fully populated value.
 */
interface CampaignEditorContextValue {
  mode: 'create' | 'edit';
  /** Edit: the loaded row. Create: synthesizeDraftCampaign()'s placeholder — id/slug/is_builtin/status read fine as constants; never read for `trigger`, see below. */
  campaign: Campaign;
  /** The trigger driving §3 Reward and §6 Priority. Edit: campaign.trigger, fixed. Create: the merchant's live pick from §2 — read this, never campaign.trigger, so those sections track the radio. */
  trigger: CampaignTrigger;
  /** Create only: changing the trigger also resets config/conditions, since a stale key would fail Campaign::config_is_valid() on save. */
  onTriggerChange?: (trigger: LiteCampaignTrigger) => void;
}

export const CampaignEditorContext = createContext<CampaignEditorContextValue | null>(null);

export function useCampaignEditorContext(): CampaignEditorContextValue {
  const context = useContext(CampaignEditorContext);

  if (!context) {
    throw new Error('useCampaignEditorContext() must be called within <CampaignEditor>.');
  }

  return context;
}
