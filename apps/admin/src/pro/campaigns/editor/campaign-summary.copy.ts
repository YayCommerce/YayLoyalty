import { __, _n, sprintf } from '@wordpress/i18n';

import { parseWPDate, parseWPRelativeDate } from '@/lib/helpers/format.helper';
import {
  Campaign,
  CAMPAIGN_CONDITION_SCHEMA,
  CampaignCondition,
  CampaignTrigger,
  isCampaignScheduleInOrder,
  resolveCampaignDisplayStatus,
  resolvePurchaseMode,
} from '@/lib/schema/campaign.schema';
import { conditionTypeLabel, statusLabel, triggerLabel } from '../campaign-labels';
import { CampaignFormValues } from './campaign-editor.form';
import {
  defaultPurchaseRate,
  effectiveRateSentence,
  isDefaultPurchaseActive,
  purchaseCountingSentence,
  purchaseLimitSentences,
} from './campaign-reward.copy';

/**
 * The rail's words: "What this means" prose and the "Before it can run"
 * checklist, both pure over form values so they test without React.
 */

/* ─── "What this means": pure form values -> prose ───────────────────── */

/** What summarizeCampaign() needs beyond form values, passed in so it stays pure. */
export interface CampaignSummaryContext {
  trigger: CampaignTrigger;
  /** The built-in purchase campaign; undefined when none, or while it loads. */
  defaultPurchaseCampaign: Campaign | undefined;
  /** True until the default campaign has loaded (or when its load failed), so a multiplier's rate is not guessed. */
  isDefaultPurchasePending?: boolean;
}

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

/**
 * Rate, then how the amount is counted, then any limits: the rail spells out
 * what the collapsed Advanced block hides. A multiplier gets no rate line
 * while the default campaign is loading, rather than a wrong one.
 */
function purchaseSentences(values: CampaignFormValues, context: CampaignSummaryContext): string[] {
  const config = values.config;
  const sentences: string[] = [];
  const isMultiplier = resolvePurchaseMode(config) === 'multiplier';

  if (!isMultiplier || !context.isDefaultPurchasePending) {
    sentences.push(effectiveRateSentence(config, defaultPurchaseRate(context.defaultPurchaseCampaign)));
  }

  sentences.push(purchaseCountingSentence(config), ...purchaseLimitSentences(config));

  return sentences;
}

function signupSentence(values: CampaignFormValues): string {
  const config = values.config as { points?: number };
  const points = typeof config.points === 'number' ? config.points : 0;

  return sprintf(
    /* translators: %s: points awarded. */
    _n(
      'Awards %s point once, the first time a customer creates an account.',
      'Awards %s points once, the first time a customer creates an account.',
      points,
      'yayloyalty',
    ),
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
          _n(
            'Awards %1$s point per approved review, plus %2$s for one with a photo or video.',
            'Awards %1$s points per approved review, plus %2$s for one with a photo or video.',
            points,
            'yayloyalty',
          ),
          String(points),
          String(mediaBonus),
        )
      : sprintf(
          /* translators: %s: points per review. */
          _n('Awards %s point per approved review.', 'Awards %s points per approved review.', points, 'yayloyalty'),
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
        /* translators: %s: max points per day. */
        _n('capped at %s point per day', 'capped at %s points per day', config.daily_cap, 'yayloyalty'),
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
 * Lists conditions as counts, never values: the rail is a summary, not an
 * export of who a campaign targets. A row with an empty list narrows
 * nothing, so it is left out.
 */
function conditionsSentence(conditions: CampaignCondition[]): string {
  const clauses = conditions.map(conditionClause).filter((clause): clause is string => clause !== null);

  if (clauses.length === 0) {
    return __('No conditions.', 'yayloyalty');
  }

  return sprintf(
    /* translators: %s: semicolon-separated condition clauses, e.g. "Customer role (2); Country (3)". */
    __('Restricted to orders matching: %s.', 'yayloyalty'),
    clauses.join('; '),
  );
}

/** A list condition's clause carries its size; null when the list is empty. */
function conditionClause(condition: CampaignCondition): string | null {
  const label = conditionTypeLabel(condition.type);
  const list = conditionList(condition);

  if (!Array.isArray(list)) {
    return label;
  }

  if (list.length === 0) {
    return null;
  }

  /* translators: 1: condition type label; 2: how many values it matches. */
  return sprintf(__('%1$s (%2$d)', 'yayloyalty'), label, list.length);
}

/** The values a list condition matches; undefined for a number rule. Decided by the schema, not the value's shape: a `between` stores a bare [min, max] pair. */
function conditionList(condition: CampaignCondition): unknown {
  const kind = CAMPAIGN_CONDITION_SCHEMA[condition.type].value;

  if (kind === 'country_list') {
    return (condition.value as { countries?: unknown } | null)?.countries;
  }

  return kind.endsWith('_list') ? condition.value : undefined;
}

/* ─── "Before it can run": pure form values -> checklist ─────────────── */

/** What campaignReadiness() needs beyond form values, passed in so it stays pure. */
export interface CampaignReadinessContext {
  trigger: CampaignTrigger;
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
      ok: isCampaignScheduleInOrder(values.starts_at, values.ends_at),
    },
  ];

  // Multiplier mode never applies without an active default purchase campaign; the save itself
  // still succeeds.
  if (context.trigger === 'purchase' && resolvePurchaseMode(values.config) === 'multiplier') {
    items.push({
      label: __('Default purchase campaign exists and is active (required for multiplier mode)', 'yayloyalty'),
      ok: isDefaultPurchaseActive(context.defaultPurchaseCampaign),
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
