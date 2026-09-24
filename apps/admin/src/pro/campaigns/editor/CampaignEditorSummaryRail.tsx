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
import { conditionTypeLabel, findBuiltinCampaign, statusLabel, triggerGroup, triggerLabel } from '../campaign-labels';
import { CampaignFormValues, useCampaignEditorContext } from './campaign-editor.form';

/**
 * The right rail: "What this means", "Before it can run" and the actions,
 * all derived from live form state via watch(). No cost estimate: no
 * store-wide ledger aggregate exists, and a fake figure on a screen that
 * decides real money is worse than none. Do not add one back without a real
 * aggregate. Both save buttons are type="submit" on the editor's form so the
 * resolver runs for a draft save too; data-intent says which was pressed.
 */
export function CampaignEditorSummaryRail({
  mutation,
  onCancel,
  onDraftIntent,
  onArchive,
  onPerformance,
}: {
  mutation: UseMutationResult<Campaign, unknown, Partial<CampaignUpdateInput>>;
  onCancel: () => void;
  /** Omitted where archiving is not offered. */
  onArchive?: () => void;
  /** Fallback for Safari < 15.4, which lacks SubmitEvent.submitter. */
  onDraftIntent: () => void;
  onPerformance?: () => void;
}) {
  const { watch, formState } = useFormContext<CampaignFormValues>();
  const { campaign } = useCampaignEditorContext();
  const values = watch();

  const campaignsQuery = useCampaignsQuery({ perPage: 100 });
  const otherCampaigns = campaignsQuery.data?.data ?? [];

  const defaultPurchaseCampaign = findBuiltinCampaign(otherCampaigns, 'purchase');

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
        {/* type="button": archiving is the dialog's decision, never a form save. */}
        {onArchive && campaign.status !== 'archived' && (
          <Button type="button" variant="destructive" disabled={busy || campaign.is_builtin} onClick={onArchive}>
            {__('Archive campaign', 'yayloyalty')}
          </Button>
        )}
        {onArchive && campaign.is_builtin && (
          <p className="text-muted-foreground text-center text-xs">
            {__('Default campaigns can’t be archived. You can pause one instead.', 'yayloyalty')}
          </p>
        )}
        <div className="flex flex-wrap items-center justify-center gap-3 text-sm">
          <Button type="button" variant="link" disabled={busy} onClick={onCancel}>
            {__('Cancel', 'yayloyalty')}
          </Button>
          {onPerformance && (
            <Button type="button" variant="link" disabled={busy} onClick={onPerformance}>
              {__('Performance', 'yayloyalty')}
            </Button>
          )}
        </div>
      </div>
    </div>
  );
}

/* ─── "What this means": pure form values -> prose ───────────────────── */

/** What summarizeCampaign() needs beyond form values, passed in so it stays pure. */
export interface CampaignSummaryContext {
  trigger: CampaignTrigger;
  /** Store currency code, read once by the caller. */
  currency: string;
  /** The built-in purchase campaign; undefined when none. */
  defaultPurchaseCampaign: Campaign | undefined;
}

const ROUNDING_LABELS: Record<string, string> = {
  down: __('rounded down', 'yayloyalty'),
  nearest: __('rounded to the nearest whole point', 'yayloyalty'),
  up: __('rounded up', 'yayloyalty'),
};

/** Form values to prose, generic across every trigger. Never receives order history, so it cannot fabricate a cost figure. */
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
      // No typed view of a Pro trigger's config, so no invented specifics.
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

/** Raw `amount currency`, not parseWPCurrency, so the number matches what the Reward input shows. */
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

/** Lists conditions as counts, never values: the rail is a summary, not an export of who a campaign targets. */
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

/** What campaignReadiness() needs beyond form values, passed in so it stays pure. */
export interface CampaignReadinessContext {
  trigger: CampaignTrigger;
  /** Another campaign in the same group holds this (possibly unsaved) priority. */
  hasPriorityCollision: boolean;
  /** The built-in purchase campaign; undefined when none. */
  defaultPurchaseCampaign: Campaign | undefined;
}

export interface CampaignReadinessItem {
  label: string;
  ok: boolean;
}

export interface CampaignReadiness {
  items: CampaignReadinessItem[];
  /** What saving now produces, and when a future start flips it on its own. */
  derivedStatusLine: string;
}

/** Form values to checklist, generic across every trigger. */
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

  // Multiplier mode never applies without an active default purchase campaign; the save itself
  // still succeeds.
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
      // A Pro trigger's config round-trips untouched; "configured" only means it still carries
      // something.
      return Object.keys(config ?? {}).length > 0;
  }
}

function derivedStatusLine(values: CampaignFormValues): string {
  const savedStatus = resolveCampaignDisplayStatus(values.status, values.starts_at, values.ends_at);
  const savedLabel = statusLabel(savedStatus);

  // Only a scheduled campaign has a second, unattended transition worth mentioning.
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
