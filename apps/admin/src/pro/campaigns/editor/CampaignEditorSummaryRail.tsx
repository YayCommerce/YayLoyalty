import type { UseMutationResult } from '@tanstack/react-query';
import { Check, X } from 'lucide-react';
import { useFormContext } from 'react-hook-form';
import { __, sprintf } from '@wordpress/i18n';

import { parseWPDate, parseWPRelativeDate } from '@/lib/helpers/format.helper';
import { useCampaignsQuery } from '@/lib/queries/campaign.queries';
import {
  Campaign,
  CampaignCondition,
  CampaignTrigger,
  CampaignUpdateInput,
  resolveCampaignDisplayStatus,
  resolvePurchaseMode,
} from '@/lib/schema/campaign.schema';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { Skeleton } from '@/components/ui/skeleton';
import { conditionTypeLabel, statusLabel, triggerGroup, triggerLabel } from '../campaign-labels';
import { CampaignFormValues, useCampaignEditorContext } from './campaign-editor.form';

/**
 * The wireframe's right rail (annotation 6, docs/campaign-screens/): "What
 * this means", "Before it can run", and the three actions. Both cards are
 * DERIVED VIEWS of live form state via `watch()` — there is no stored copy
 * anywhere in this component, so every keystroke in the sections to the left
 * re-renders this without a refetch.
 *
 * NO COST ESTIMATE: the wireframe's "214 orders would have matched… $418 of
 * liability" line is intentionally absent. There is no store-wide ledger
 * aggregate to compute it from — `LedgerRepository::sum_points_for_user()`
 * is per-customer only, and `CampaignTable.tsx`'s own docblock says the same
 * about `pointsLast30Days` needing `campaign-points-dummy.ts` to fake it for
 * the list. That trick is not repeated here: this screen decides real
 * merchant money, and a plausible-looking fake number here is worse than no
 * number at all. Do not "fix" this by adding one back without a real
 * aggregate to read.
 *
 * Both save actions submit the SAME `<form>` `CampaignEditor.tsx` renders (via
 * `type="submit"`, not a separate click handler) so its resolver-level condition
 * validation (`mergeConditionErrors`, campaign-editor.form.ts) — which blocks an
 * invalid condition value from ever reaching the network — still runs for
 * "Save as draft" exactly as it does for "Save campaign". Which one was pressed
 * is read from the native `SubmitEvent.submitter` in `CampaignEditor.tsx`'s
 * `handleSubmit` callback via this button's `data-intent` attribute, with
 * `onDraftIntent` as a same-signal fallback for browsers lacking `submitter`.
 */
export function CampaignEditorSummaryRail({
  mutation,
  onCancel,
  onDraftIntent,
}: {
  mutation: UseMutationResult<Campaign, unknown, CampaignUpdateInput>;
  onCancel: () => void;
  /** Fallback for Safari < 15.4, which lacks `SubmitEvent.submitter` — see `CampaignEditor.tsx`. */
  onDraftIntent: () => void;
}) {
  const { watch, formState } = useFormContext<CampaignFormValues>();
  const { campaign } = useCampaignEditorContext();
  const values = watch();

  // Same list query the Priority and Reward sections in `CampaignEditorSections.tsx` already
  // read (`useCampaignsQuery({ perPage: 100 })`) — reused here rather than fetched twice under a
  // different key, since react-query dedupes identical query keys anyway.
  const campaignsQuery = useCampaignsQuery({ perPage: 100 });
  const otherCampaigns = campaignsQuery.data?.data ?? [];

  const defaultPurchaseCampaign = otherCampaigns.find(
    (candidate) => candidate.is_builtin && candidate.trigger === 'purchase',
  );

  const group = triggerGroup(campaign.trigger);
  const hasPriorityCollision = otherCampaigns.some(
    (row) => row.id !== campaign.id && triggerGroup(row.trigger) === group && row.priority === values.priority,
  );

  const currency = window.yayLoyaltyMeta.wcMeta.currency_data.currency;

  const sentences = summarizeCampaign(values, {
    trigger: campaign.trigger,
    currency,
    defaultPurchaseCampaign,
  });

  const readiness = campaignReadiness(values, {
    trigger: campaign.trigger,
    hasPriorityCollision,
    defaultPurchaseCampaign,
  });

  // Both save buttons disabled while the mutation is in flight OR RHF's own submit handler
  // (the conditions guard, the resolver) hasn't finished — never just one or the other.
  const busy = mutation.isPending || formState.isSubmitting;

  return (
    <div className="flex w-full flex-col gap-4 lg:sticky lg:top-6 lg:w-85 lg:shrink-0">
      <Card>
        <CardHeader>
          <CardTitle className="text-base">{__('What this means', 'yayloyalty')}</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-3 text-sm">
          {sentences.map((sentence, index) => (
            <p key={index}>{sentence}</p>
          ))}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="text-base">{__('Before it can run', 'yayloyalty')}</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-3">
          {campaignsQuery.isPending ? (
            <Skeleton className="h-16 w-full" />
          ) : (
            <ul className="flex flex-col gap-1.5 text-sm">
              {readiness.items.map((item, index) => (
                <li key={index} className="flex items-start gap-2">
                  {item.ok ? (
                    <Check className="text-success mt-0.5 size-4 shrink-0" />
                  ) : (
                    <X className="text-destructive mt-0.5 size-4 shrink-0" />
                  )}
                  <span className={cn(!item.ok && 'text-muted-foreground')}>{item.label}</span>
                </li>
              ))}
            </ul>
          )}
          <Separator />
          <p className="text-muted-foreground text-xs">{readiness.derivedStatusLine}</p>
        </CardContent>
      </Card>

      <div className="flex flex-col gap-2">
        <Button type="submit" disabled={busy}>
          {mutation.isPending ? __('Saving…', 'yayloyalty') : __('Save campaign', 'yayloyalty')}
        </Button>
        <Button type="submit" variant="outline" data-intent="draft" disabled={busy} onClick={onDraftIntent}>
          {__('Save as draft', 'yayloyalty')}
        </Button>
        <Button type="submit" variant="destructive" data-intent="archive" disabled={busy}>
          {__('Archive campaign', 'yayloyalty')}
        </Button>
        <div className="flex flex-wrap items-center justify-center gap-3 text-sm">``
          <Button type="button" variant="link" disabled={busy} onClick={onCancel}>
            {__('Cancel', 'yayloyalty')}
          </Button>
          <Button type="button" variant="link" disabled={busy}>
            {__('Performance', 'yayloyalty')}
          </Button>
        </div>
      </div>
    </div>
  );
}

/* ─── "What this means": pure form values -> prose ───────────────────── */

/**
 * Everything the rail's "What this means" card (above) needs beyond the
 * form's own values — read-only campaign data plus the one lookup
 * (`defaultPurchaseCampaign`) the multiplier branch depends on. Passed in
 * rather than fetched here, so `summarizeCampaign()` stays pure and testable
 * without a query client (phase-06's Architecture note).
 */
export interface CampaignSummaryContext {
  trigger: CampaignTrigger;
  /** Store currency code (`window.yayLoyaltyMeta.wcMeta.currency_data.currency`) — read once by the caller. */
  currency: string;
  /**
   * The built-in `purchase` campaign, when one exists — same lookup the
   * Reward section (`CampaignEditorSections.tsx`) already does
   * (`useCampaignsQuery` filtered to `is_builtin && trigger === 'purchase'`).
   * `undefined` means none was found.
   */
  defaultPurchaseCampaign: Campaign | undefined;
}

const ROUNDING_LABELS: Record<string, string> = {
  down: __('rounded down', 'yayloyalty'),
  nearest: __('rounded to the nearest whole point', 'yayloyalty'),
  up: __('rounded up', 'yayloyalty'),
};

/**
 * Pure `form values -> prose` for the rail. GENERIC across all seven
 * triggers — Black Friday 2x (the wireframe's example) is one instance of the
 * `purchase` + `multiplier` branch, never special-cased by name or id.
 *
 * Deliberately omits any cost/liability estimate: no store-wide ledger
 * aggregate exists to compute one from (`LedgerRepository::sum_points_for_user()`
 * is per-customer only) — see `campaignReadiness()`'s docblock below and
 * `CampaignEditorSummaryRail`'s above for the fuller note. This function never
 * receives order history, so it cannot fabricate that figure even by mistake.
 */
export function summarizeCampaign(values: CampaignFormValues, context: CampaignSummaryContext): string[] {
  const sentences: string[] = [];

  switch (context.trigger) {
    case 'purchase':
      sentences.push(...purchaseSentences(values, context));
      break;
    case 'signup':
      sentences.push(signupSentence(values));
      break;
    case 'review':
      sentences.push(...reviewSentences(values));
      break;
    default:
      // Every Pro trigger with no Lite field map (`RewardFieldsUnknown`,
      // CampaignEditorSections.tsx) — no invented specifics, since this build
      // has no typed view of its config.
      sentences.push(
        sprintf(
          /* translators: %s: the trigger's display label, e.g. "Birthday". */
          __('Awards points on "%s", using the settings configured above.', 'yayloyalty'),
          triggerLabel(context.trigger),
        ),
      );
  }

  sentences.push(dateWindowSentence(values.starts_at, values.ends_at));
  sentences.push(conditionsSentence(values.conditions));

  return sentences;
}

function purchaseSentences(values: CampaignFormValues, context: CampaignSummaryContext): string[] {
  const config = values.config as {
    mode?: string;
    multiplier?: number;
    points_per_unit?: number;
    base?: string;
    rounding?: string;
    min_subtotal?: number;
  };
  const mode = resolvePurchaseMode(config);
  const currency = context.currency;
  const base =
    config.base === 'before_discounts' ? __('before discounts', 'yayloyalty') : __('after discounts', 'yayloyalty');
  const rounding = ROUNDING_LABELS[config.rounding ?? 'down'] ?? ROUNDING_LABELS.down;
  const minSubtotal = typeof config.min_subtotal === 'number' && config.min_subtotal > 0 ? config.min_subtotal : 0;

  const sentences: string[] = [];

  if (mode === 'multiplier') {
    const multiplier = typeof config.multiplier === 'number' ? config.multiplier : 1;
    const defaultRate =
      typeof context.defaultPurchaseCampaign?.config.points_per_unit === 'number'
        ? context.defaultPurchaseCampaign.config.points_per_unit
        : undefined;

    sentences.push(
      defaultRate !== undefined
        ? sprintf(
            /* translators: 1: the multiplier, e.g. "2"; 2: the default rate; 3: currency code; 4: resulting rate. */
            __(
              'Multiplies the default purchase campaign’s rate (%2$s point(s) per 1 %3$s) by %1$s×, for %4$s point(s) per 1 %3$s.',
              'yayloyalty',
            ),
            String(multiplier),
            String(defaultRate),
            currency,
            String(multiplier * defaultRate),
          )
        : sprintf(
            /* translators: %s: the multiplier, e.g. "2". */
            __(
              'Multiplies the default purchase campaign’s rate by %s× — that campaign has no rate set yet.',
              'yayloyalty',
            ),
            String(multiplier),
          ),
    );
    // Annotation 2 correction (reference-wireframe-vs-schema.md): only the rate itself is
    // inherited. Rounding, base amount, exclusions and minimum subtotal are always this
    // campaign's own config, in both modes.
    sentences.push(
      __(
        'It uses its own rounding, base amount, minimum subtotal and exclusions below — only the rate is inherited from the default campaign.',
        'yayloyalty',
      ),
    );
  } else {
    const pointsPerUnit = typeof config.points_per_unit === 'number' ? config.points_per_unit : 0;

    sentences.push(
      sprintf(
        /* translators: 1: points per unit; 2: currency code. */
        __('Awards %1$s point(s) per 1 %2$s spent.', 'yayloyalty'),
        String(pointsPerUnit),
        currency,
      ),
    );
  }

  sentences.push(
    minSubtotal > 0
      ? sprintf(
          /* translators: 1: "after discounts"/"before discounts"; 2: rounding rule; 3: formatted minimum subtotal. */
          __('Calculated %1$s and %2$s, on orders of at least %3$s.', 'yayloyalty'),
          base,
          rounding,
          formatMinSubtotal(minSubtotal, currency),
        )
      : sprintf(
          /* translators: 1: "after discounts"/"before discounts"; 2: rounding rule. */
          __('Calculated %1$s and %2$s, with no minimum order subtotal.', 'yayloyalty'),
          base,
          rounding,
        ),
  );

  return sentences;
}

/** Plain `amount currency` rather than `parseWPCurrency` (store-locale formatted) — the config's raw number is already in the store's base currency, so this keeps the sentence's number matching what the Reward section's input shows. */
function formatMinSubtotal(amount: number, currency: string): string {
  return `${amount} ${currency}`;
}

function signupSentence(values: CampaignFormValues): string {
  const config = values.config as { points?: number };
  const points = typeof config.points === 'number' ? config.points : 0;

  return sprintf(
    /* translators: %s: points awarded. */
    __('Awards %s point(s) once, the first time a customer creates an account.', 'yayloyalty'),
    String(points),
  );
}

function reviewSentences(values: CampaignFormValues): string[] {
  const config = values.config as {
    points?: number;
    media_bonus_points?: number;
    require_verified?: boolean;
    one_per_product?: boolean;
    daily_cap?: number;
  };
  const points = typeof config.points === 'number' ? config.points : 0;
  const mediaBonus = typeof config.media_bonus_points === 'number' ? config.media_bonus_points : 0;

  const sentences: string[] = [
    mediaBonus > 0
      ? sprintf(
          /* translators: 1: points per review; 2: bonus points for a photo/video review. */
          __('Awards %1$s point(s) per approved review, plus %2$s for one with a photo or video.', 'yayloyalty'),
          String(points),
          String(mediaBonus),
        )
      : sprintf(
          /* translators: %s: points per review. */
          __('Awards %s point(s) per approved review.', 'yayloyalty'),
          String(points),
        ),
  ];

  const rules: string[] = [];

  if (config.require_verified) {
    rules.push(__('verified purchases only', 'yayloyalty'));
  }

  if (config.one_per_product) {
    rules.push(__('one review per product', 'yayloyalty'));
  }

  if (typeof config.daily_cap === 'number' && config.daily_cap > 0) {
    rules.push(
      sprintf(
        /* translators: %s: max points per day. */ __('capped at %s point(s) per day', 'yayloyalty'),
        String(config.daily_cap),
      ),
    );
  }

  if (rules.length > 0) {
    sentences.push(
      sprintf(
        /* translators: %s: comma-separated eligibility rules. */ __('Eligibility: %s.', 'yayloyalty'),
        rules.join(', '),
      ),
    );
  }

  return sentences;
}

function dateWindowSentence(startsAt: string | null, endsAt: string | null): string {
  if (!startsAt && !endsAt) {
    return __('Runs continuously — no start or end date is set.', 'yayloyalty');
  }

  if (startsAt && endsAt) {
    return sprintf(
      /* translators: 1: start date; 2: end date (exclusive). */
      __('Runs from %1$s up to (but not including) %2$s.', 'yayloyalty'),
      parseWPDate(startsAt),
      parseWPDate(endsAt),
    );
  }

  if (startsAt) {
    return sprintf(
      /* translators: %s: start date. */ __('Starts %s, with no end date.', 'yayloyalty'),
      parseWPDate(startsAt),
    );
  }

  return sprintf(
    /* translators: %s: end date (exclusive). */
    __('Runs until %s, exclusive, with no start date.', 'yayloyalty'),
    parseWPDate(endsAt as string),
  );
}

/**
 * Security: `customer_ids` renders as a COUNT here, never the ids themselves
 * — the rail is a summary, not an export of who this campaign targets. Every
 * other list-shaped condition gets the same count treatment for consistency,
 * even though only `customer_ids` is a hard requirement.
 */
function conditionsSentence(conditions: CampaignCondition[]): string {
  if (conditions.length === 0) {
    return __('Applies to every order in the date range above — no conditions are set.', 'yayloyalty');
  }

  const clauses = conditions.map(conditionClause);

  return sprintf(
    /* translators: %s: semicolon-separated list of condition clauses, e.g. "Customer role (2 values); Specific customers (5 customers)". */
    __('Restricted to orders matching: %s.', 'yayloyalty'),
    clauses.join('; '),
  );
}

function conditionClause(condition: CampaignCondition): string {
  const label = conditionTypeLabel(condition.type);

  if (Array.isArray(condition.value)) {
    return sprintf(
      /* translators: 1: condition type label; 2: number of values it matches. */
      __('%1$s (%2$d value(s))', 'yayloyalty'),
      label,
      condition.value.length,
    );
  }

  if (condition.type === 'country') {
    const value = condition.value as { countries?: unknown };
    const count = Array.isArray(value?.countries) ? value.countries.length : 0;

    return sprintf(
      /* translators: 1: condition type label; 2: number of countries. */ __('%1$s (%2$d)', 'yayloyalty'),
      label,
      count,
    );
  }

  return label;
}

/* ─── "Before it can run": pure form values -> checklist ─────────────── */

/**
 * Everything `campaignReadiness()` needs beyond the form's own values.
 * `hasPriorityCollision` and `defaultPurchaseCampaign` are both lookups over
 * `useCampaignsQuery` that only make sense inside a component — computed by
 * `CampaignEditorSummaryRail` above and handed in, so `campaignReadiness()`
 * stays a pure function testable without a query client (same split as
 * `summarizeCampaign()`).
 */
export interface CampaignReadinessContext {
  trigger: CampaignTrigger;
  /**
   * True when another campaign in the same `triggerGroup()` already holds
   * this campaign's (possibly just-edited) priority — the same tie the
   * Priority section's ladder (`CampaignEditorSections.tsx`) and
   * `CampaignTable.tsx`'s "Priority tie" badge already surface, re-read here
   * for the checklist.
   */
  hasPriorityCollision: boolean;
  /**
   * The built-in `purchase` campaign, when one exists — same lookup the
   * Reward section's purchase fields (`CampaignEditorSections.tsx`) use for
   * their own Alert (`useCampaignsQuery` filtered to
   * `is_builtin && trigger === 'purchase'`).
   */
  defaultPurchaseCampaign: Campaign | undefined;
}

export interface CampaignReadinessItem {
  label: string;
  ok: boolean;
}

export interface CampaignReadiness {
  items: CampaignReadinessItem[];
  /**
   * Annotation 6 / wireframe: "It becomes Scheduled on save, and Active by
   * itself on 27 November." — computed with `resolveCampaignDisplayStatus`
   * (what saving right now would produce) and `parseWPRelativeDate` (when a
   * still-future start date will flip it again on its own), never hand-rolled
   * date math.
   */
  derivedStatusLine: string;
}

/**
 * Pure `form values -> checklist` for the rail's "Before it can run" card.
 * GENERIC across all seven triggers — see `summarizeCampaign()`'s docblock
 * above for the same requirement.
 */
export function campaignReadiness(values: CampaignFormValues, context: CampaignReadinessContext): CampaignReadiness {
  const items: CampaignReadinessItem[] = [
    {
      label: __('Name, trigger and reward set', 'yayloyalty'),
      ok: values.name.trim().length > 0 && isRewardConfigured(context.trigger, values.config),
    },
    {
      label: __('Dates are coherent (end after start)', 'yayloyalty'),
      ok: !values.starts_at || !values.ends_at || values.starts_at < values.ends_at,
    },
    {
      label: __('Priority does not collide with another campaign in this group', 'yayloyalty'),
      ok: !context.hasPriorityCollision,
    },
  ];

  // Annotation 2's real consequence (reference-wireframe-vs-schema.md): multiplier mode makes
  // `PurchaseHandler::is_eligible()` reject every order outright when the default purchase
  // campaign is missing or not active — this campaign would save fine and then never apply.
  if (context.trigger === 'purchase' && resolvePurchaseMode(values.config) === 'multiplier') {
    items.push({
      label: __('Default purchase campaign exists and is active (required for multiplier mode)', 'yayloyalty'),
      ok: context.defaultPurchaseCampaign?.status === 'active',
    });
  }

  return {
    items,
    derivedStatusLine: derivedStatusLine(values),
  };
}

function isRewardConfigured(trigger: CampaignTrigger, config: Record<string, unknown>): boolean {
  switch (trigger) {
    case 'purchase':
      return resolvePurchaseMode(config) === 'multiplier'
        ? typeof config.multiplier === 'number'
        : typeof config.points_per_unit === 'number';
    case 'signup':
    case 'review':
      return typeof config.points === 'number';
    default:
      // A Pro trigger with no Lite field map (`RewardFieldsUnknown`, CampaignEditorSections.tsx)
      // round-trips its config untouched — "configured" here just means it still carries
      // whatever the campaign already had, not that this build validated any of it.
      return Object.keys(config ?? {}).length > 0;
  }
}

function derivedStatusLine(values: CampaignFormValues): string {
  const savedStatus = resolveCampaignDisplayStatus(values.status, values.starts_at, values.ends_at);
  const savedLabel = statusLabel(savedStatus);

  // Only a status that is 'active' in storage but not yet live (a future `starts_at`) has a
  // second, unattended transition worth mentioning — every other saved status stays put
  // until a merchant changes it again.
  if (savedStatus === 'scheduled' && values.starts_at) {
    const futureStatus = resolveCampaignDisplayStatus(
      values.status,
      values.starts_at,
      values.ends_at,
      values.starts_at,
    );

    return sprintf(
      /* translators: 1: status label right after saving, e.g. "Scheduled"; 2: status label once the start date arrives, e.g. "Active"; 3: relative time until then, e.g. "in 3 days". */
      __('It becomes %1$s on save, and %2$s by itself %3$s.', 'yayloyalty'),
      savedLabel,
      statusLabel(futureStatus),
      parseWPRelativeDate(values.starts_at),
    );
  }

  return sprintf(
    /* translators: %s: status label right after saving, e.g. "Active". */ __('It becomes %s on save.', 'yayloyalty'),
    savedLabel,
  );
}
