import { ReactNode } from 'react';
import { Controller, useFormContext, useWatch } from 'react-hook-form';
import { getSettings } from '@wordpress/date';
import { __, sprintf } from '@wordpress/i18n';

import { joinStoredDateTime, parseWPDate, parseWPTime, splitStoredDateTime } from '@/lib/helpers/format.helper';
import { CampaignTrigger, LITE_CAMPAIGN_TRIGGERS, LiteCampaignTrigger } from '@/lib/schema/campaign.schema';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { DatePicker } from '@/components/ui/date-picker';
import { Field, FieldContent, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { NumberInputInput, NumberInputRoot } from '@/components/ui/number-input';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { statusLabel, triggerLabel } from '../campaign-labels';
import { CampaignFormValues, useCampaignEditorContext } from './campaign-editor.form';
import { CampaignEditorConditions } from './CampaignEditorConditions';
import { CampaignEditorReward } from './CampaignEditorReward';

/** The editor's six sections in order. Conditions and Reward live in their own files; they are the two large enough to earn it. */
export function CampaignEditorSections() {
  return (
    <div className="flex flex-col gap-4">
      <CampaignEditorSection step={1} title={__('Basics', 'yayloyalty')}>
        <CampaignEditorBasics />
      </CampaignEditorSection>

      <CampaignEditorSection step={2} title={__('Trigger', 'yayloyalty')}>
        <CampaignEditorTrigger />
      </CampaignEditorSection>

      <CampaignEditorSection step={3} title={__('Reward', 'yayloyalty')}>
        <CampaignEditorReward />
      </CampaignEditorSection>

      <CampaignEditorSection step={4} title={__('Conditions', 'yayloyalty')}>
        <CampaignEditorConditions />
      </CampaignEditorSection>

      <CampaignEditorSection step={5} title={__('Schedule', 'yayloyalty')}>
        <CampaignEditorSchedule />
      </CampaignEditorSection>

      <CampaignEditorSection step={6} title={__('Priority', 'yayloyalty')}>
        <CampaignEditorPriority />
      </CampaignEditorSection>
    </div>
  );
}

/* ─── the shared numbered-card shell ─────────────────────────────────── */

/** Numbered card shell for one section. */
function CampaignEditorSection({ step, title, children }: { step: number; title: string; children?: ReactNode }) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2.5 text-base">
          <span className="bg-foreground text-background flex size-5.5 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold">
            {step}
          </span>
          {title}
        </CardTitle>
      </CardHeader>
      {children && <CardContent className="flex flex-col gap-4">{children}</CardContent>}
    </Card>
  );
}

/* ─── §1 Basics ──────────────────────────────────────────────────────── */

/** CAMPAIGN_STATUSES minus archived: archiving is its own flow, and Campaign::set_status() throws for a built-in. */
const EDITABLE_STATUSES = ['active', 'paused', 'draft'] as const;

/** Name and status. slug is shown read-only in the page header, not here. */
function CampaignEditorBasics() {
  const { register, control, formState } = useFormContext<CampaignFormValues>();

  return (
    <>
      <Field data-invalid={!!formState.errors.name}>
        <FieldLabel htmlFor="campaign-basics-name">{__('Name', 'yayloyalty')}</FieldLabel>
        <FieldContent>
          <Input id="campaign-basics-name" aria-invalid={!!formState.errors.name} {...register('name')} />
          <FieldError errors={[formState.errors.name]} />
        </FieldContent>
      </Field>

      <Field>
        <FieldLabel>{__('Status', 'yayloyalty')}</FieldLabel>
        <FieldContent>
          <Controller
            control={control}
            name="status"
            render={({ field }) => (
              // Re-clicking the active item yields an empty string; never write that back.
              <Segmented value={field.value} onValueChange={(value) => value && field.onChange(value)}>
                {EDITABLE_STATUSES.map((status) => (
                  <SegmentedItem key={status} value={status}>
                    {statusLabel(status)}
                  </SegmentedItem>
                ))}
              </Segmented>
            )}
          />
        </FieldContent>
      </Field>

      <FieldDescription>
        {__('The customer never sees the campaign name — it is for your team only.', 'yayloyalty')}
      </FieldDescription>
    </>
  );
}

/* ─── §2 Trigger ─────────────────────────────────────────────────────── */

/** The purchase copy names no order status on purpose: settings has none to point at. */
function triggerDescription(trigger: CampaignTrigger): string {
  switch (trigger) {
    case 'purchase':
      return __('Evaluated when the order completes.', 'yayloyalty');
    case 'signup':
      return __('Evaluated once, the first time a customer creates an account.', 'yayloyalty');
    case 'review':
      return __('Evaluated when a product review is published.', 'yayloyalty');
    case 'birthday':
      return __("Evaluated on the customer's birthday.", 'yayloyalty');
    case 'first_order':
      return __("Evaluated on a customer's first order.", 'yayloyalty');
    case 'order_count':
      return __('Evaluated when a customer reaches an order-count milestone.', 'yayloyalty');
    case 'spend_milestone':
      return __('Evaluated when a customer reaches a spend milestone.', 'yayloyalty');
    default:
      return '';
  }
}

/** Dispatches on mode: create picks a trigger, edit only ever shows the one already saved. */
function CampaignEditorTrigger() {
  const { mode } = useCampaignEditorContext();

  return mode === 'create' ? <CampaignEditorTriggerEditable /> : <CampaignEditorTriggerReadOnly />;
}

/**
 * Read-only trigger radio: trigger is immutable after create and renders
 * from the loaded Campaign, never form state. Offers only the Lite triggers,
 * since no Pro trigger has a field map.
 */
function CampaignEditorTriggerReadOnly() {
  const { campaign } = useCampaignEditorContext();

  // A stored Pro trigger falls outside the offered list; include it so the control never
  // renders unselected.
  const triggers: readonly CampaignTrigger[] = (LITE_CAMPAIGN_TRIGGERS as readonly CampaignTrigger[]).includes(
    campaign.trigger,
  )
    ? LITE_CAMPAIGN_TRIGGERS
    : [...LITE_CAMPAIGN_TRIGGERS, campaign.trigger];

  return (
    <div className="flex flex-col gap-3">
      <RadioGroup value={campaign.trigger} disabled className="gap-3">
        {triggers.map((trigger) => (
          <div key={trigger} className="flex items-start gap-2.5">
            <RadioGroupItem id={`campaign-trigger-${trigger}`} value={trigger} className="mt-0.5" />
            <FieldContent>
              <FieldLabel htmlFor={`campaign-trigger-${trigger}`}>{triggerLabel(trigger)}</FieldLabel>
              <FieldDescription>{triggerDescription(trigger)}</FieldDescription>
            </FieldContent>
          </div>
        ))}
      </RadioGroup>

      <FieldDescription>
        {__(
          'Decides which group of campaigns this one competes with. Set when the campaign was created and cannot be changed afterwards.',
          'yayloyalty',
        )}
      </FieldDescription>
    </div>
  );
}

/**
 * Editable trigger radio for create mode, restricted to the three Lite
 * triggers — the only ones a template or "Start from scratch" can offer.
 * onTriggerChange also resets config/conditions; see CampaignEditorContextValue.
 */
function CampaignEditorTriggerEditable() {
  const { trigger, onTriggerChange } = useCampaignEditorContext();

  return (
    <div className="flex flex-col gap-3">
      <RadioGroup
        value={trigger}
        onValueChange={(value) => value && onTriggerChange?.(value as LiteCampaignTrigger)}
        className="gap-3"
      >
        {LITE_CAMPAIGN_TRIGGERS.map((candidate) => (
          <div key={candidate} className="flex items-start gap-2.5">
            <RadioGroupItem id={`campaign-trigger-${candidate}`} value={candidate} className="mt-0.5" />
            <FieldContent>
              <FieldLabel htmlFor={`campaign-trigger-${candidate}`}>{triggerLabel(candidate)}</FieldLabel>
              <FieldDescription>{triggerDescription(candidate)}</FieldDescription>
            </FieldContent>
          </div>
        ))}
      </RadioGroup>

      <FieldDescription>
        {__(
          'Decides which group of campaigns this one competes with, and which fields Reward shows below. Cannot be changed once the campaign is saved.',
          'yayloyalty',
        )}
      </FieldDescription>
    </div>
  );
}

/* ─── §5 Schedule ────────────────────────────────────────────────────── */

/**
 * starts_at/ends_at are nullable, exclusive-end UTC strings held raw in form
 * state. The picker shows site-local time: utcStringToSiteLocalParts and
 * siteLocalPartsToUtcString convert at the inputs only. The derived sentence
 * spells out the exclusive end, which two date fields cannot express.
 */
function CampaignEditorSchedule() {
  const { control, setValue, formState } = useFormContext<CampaignFormValues>();
  const startsAt = useWatch({ control, name: 'starts_at' });
  const endsAt = useWatch({ control, name: 'ends_at' });
  const zoneLabel = siteTimezoneLabel();

  return (
    <div className="flex flex-col gap-4">
      <ScheduleBoundField
        label={__('Starts', 'yayloyalty')}
        value={startsAt}
        onChange={(next) => setValue('starts_at', next, { shouldDirty: true, shouldValidate: true })}
      />

      <div className="flex flex-col gap-1.5">
        <ScheduleBoundField
          label={__('Ends', 'yayloyalty')}
          value={endsAt}
          onChange={(next) => setValue('ends_at', next, { shouldDirty: true, shouldValidate: true })}
        />
        <FieldError errors={[formState.errors.ends_at]} />
      </div>

      <div className="bg-muted flex flex-col gap-1 rounded-lg p-3 text-sm">
        <p>{describeWindow(startsAt, endsAt, zoneLabel)}</p>
      </div>
    </div>
  );
}

function ScheduleBoundField({
  label,
  value,
  onChange,
}: {
  label: string;
  value: string | null;
  onChange: (next: string | null) => void;
}) {
  // Site-local for editing; onChange converts back to the raw UTC string.
  const { date, time } = utcStringToSiteLocalParts(value);

  function handleDateChange(nextDate: Date | undefined) {
    onChange(siteLocalPartsToUtcString(localDateToDateString(nextDate), time || '00:00'));
  }

  function handleTimeChange(nextTime: string) {
    if (!date) {
      return;
    }

    onChange(siteLocalPartsToUtcString(date, nextTime));
  }

  return (
    <Field orientation="responsive">
      <FieldLabel>{label}</FieldLabel>
      <FieldContent>
        <div className="flex flex-wrap items-center gap-2">
          {/* Keyed by `date`: DatePicker falls back to an internal uncontrolled cache whenever
              `value` is undefined (empty), so clearing a field that was picked while it started
              empty would silently redisplay that stale date. A fresh key each time forces a
              remount instead, so "no date" always renders as no date. */}
          <DatePicker
            key={date}
            value={dateStringToLocalDate(date)}
            onValueChange={handleDateChange}
            placeholder={__('No date — always on', 'yayloyalty')}
          />
          <Input
            type="time"
            className="w-28"
            value={time}
            disabled={!date}
            onChange={(event) => handleTimeChange(event.target.value)}
          />
          {date && (
            <Button type="button" variant="ghost" size="sm" onClick={() => onChange(null)}>
              {__('Clear', 'yayloyalty')}
            </Button>
          )}
        </div>
      </FieldContent>
    </Field>
  );
}

/* ─── UTC <-> site-local, the picker's tz conversion edge ────────────── */

/**
 * The site's UTC offset in minutes. A fixed offset, not DST-aware: it can
 * disagree with dateI18n right around a transition, and keeps this
 * dependency-free.
 */
function getSiteOffsetMinutes(): number {
  const settings = getSettings() as unknown as { timezone?: { offset?: number } };
  const offsetHours = typeof settings.timezone?.offset === 'number' ? settings.timezone.offset : 0;

  return Math.round(offsetHours * 60);
}

/** Stored UTC to the site-local pair the picker shows; exact inverse of siteLocalPartsToUtcString. */
function utcStringToSiteLocalParts(stored: string | null | undefined): { date: string; time: string } {
  const { date, time } = splitStoredDateTime(stored);

  if (!date) {
    return { date: '', time: '' };
  }

  const [year, month, day] = date.split('-').map(Number);
  const [hour, minute] = (time || '00:00').split(':').map(Number);
  const siteMs = Date.UTC(year, (month || 1) - 1, day || 1, hour || 0, minute || 0) + getSiteOffsetMinutes() * 60000;

  return utcMsToParts(siteMs);
}

/** Reverse of utcStringToSiteLocalParts. */
function siteLocalPartsToUtcString(date: string, time: string): string | null {
  if (!date) {
    return null;
  }

  const [year, month, day] = date.split('-').map(Number);
  const [hour, minute] = (time || '00:00').split(':').map(Number);
  const utcMs = Date.UTC(year, (month || 1) - 1, day || 1, hour || 0, minute || 0) - getSiteOffsetMinutes() * 60000;
  const { date: utcDate, time: utcTime } = utcMsToParts(utcMs);

  return joinStoredDateTime(utcDate, utcTime);
}

/** A timestamp's UTC calendar fields; no local-tz involvement. */
function utcMsToParts(ms: number): { date: string; time: string } {
  const shifted = new Date(ms);
  const pad = (n: number) => String(n).padStart(2, '0');

  return {
    date: `${shifted.getUTCFullYear()}-${pad(shifted.getUTCMonth() + 1)}-${pad(shifted.getUTCDate())}`,
    time: `${pad(shifted.getUTCHours())}:${pad(shifted.getUTCMinutes())}`,
  };
}

/* ─── date <-> Date, for the picker only — never a bare `new Date(str)` ─ */

/**
 * A day-of-month container for the DatePicker, built from components:
 * new Date(str) parses as browser-local and shifts the day. Read back only
 * with the local getters in localDateToDateString.
 */
function dateStringToLocalDate(dateStr: string): Date | undefined {
  if (!dateStr) {
    return undefined;
  }

  const [year, month, day] = dateStr.split('-').map(Number);

  if (!year || !month || !day) {
    return undefined;
  }

  return new Date(year, month - 1, day);
}

/** Reverse of dateStringToLocalDate; local getters only. */
function localDateToDateString(date: Date | undefined): string {
  if (!date) {
    return '';
  }

  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');

  return `${year}-${month}-${day}`;
}

/** Formats a stored moment for the sentence. A real UTC instant, so dateI18n's own site-tz conversion is not double-shifted. */
function formatStoredMoment(stored: string): string {
  const { date, time } = splitStoredDateTime(stored);
  const [year, month, day] = date.split('-').map(Number);
  const [hour, minute] = (time || '00:00').split(':').map(Number);
  const utcInstant = new Date(Date.UTC(year, (month || 1) - 1, day || 1, hour || 0, minute || 0));

  return `${parseWPDate(utcInstant)} ${parseWPTime(utcInstant)}`;
}

/**
 * The last day the campaign runs. ends_at is exclusive, so one day earlier
 * when it lands on a site-local midnight; a non-midnight end runs through
 * its own day. Subtracts 24h from the instant, not a calendar field, so the
 * result stays on the right site-local day.
 */
function formatDayBeforeStoredMoment(stored: string): string {
  const { date, time } = splitStoredDateTime(stored);
  const [year, month, day] = date.split('-').map(Number);
  const [hour, minute] = (time || '00:00').split(':').map(Number);
  const utcInstant = Date.UTC(year, (month || 1) - 1, day || 1, hour || 0, minute || 0);
  const isSiteLocalMidnight = utcStringToSiteLocalParts(stored).time === '00:00';
  const lastDayInstant = isSiteLocalMidnight ? utcInstant - 24 * 60 * 60 * 1000 : utcInstant;

  return parseWPDate(new Date(lastDayInstant));
}

/**
 * The site timezone as a label: the IANA name when set, else the offset,
 * else plain "UTC". WP's offsetFormatted is unsigned and "0" for an unset
 * manual offset, so that case falls through rather than rendering "UTC0",
 * and a negative value already carries its sign.
 */
function siteTimezoneLabel(): string {
  const settings = getSettings() as unknown as {
    timezone?: { string?: string; offsetFormatted?: string; offset?: number };
  };
  const timezone = settings.timezone;

  if (timezone?.string) {
    return timezone.string;
  }

  if (timezone?.offsetFormatted && timezone.offsetFormatted !== '0') {
    const signed = timezone.offsetFormatted.startsWith('-') ? timezone.offsetFormatted : `+${timezone.offsetFormatted}`;

    return sprintf(/* translators: %s: a signed UTC offset, e.g. "+5:30" or "-5". */ __('UTC%s', 'yayloyalty'), signed);
  }

  if (typeof timezone?.offset === 'number' && timezone.offset !== 0) {
    const sign = timezone.offset > 0 ? '+' : '-';
    return sprintf(
      /* translators: 1: +/-, 2: hours offset from UTC. */ __('UTC%1$s%2$s', 'yayloyalty'),
      sign,
      String(Math.abs(timezone.offset)),
    );
  }

  return __('UTC', 'yayloyalty');
}

/** Spells out the exclusive end in words. */
function describeWindow(startsAt: string | null, endsAt: string | null, zoneLabel: string): string {
  if (!startsAt && !endsAt) {
    return __('Always on — no start or end date is set.', 'yayloyalty');
  }

  if (startsAt && endsAt) {
    return sprintf(
      /* translators: 1: start date+time, 2: end date+time (exclusive), 3: the last day the campaign actually runs, 4: site timezone. */
      __('Runs from %1$s up to but not including %2$s — ending as %3$s closes. Times are in %4$s.', 'yayloyalty'),
      formatStoredMoment(startsAt),
      formatStoredMoment(endsAt),
      formatDayBeforeStoredMoment(endsAt),
      zoneLabel,
    );
  }

  if (startsAt) {
    return sprintf(
      /* translators: 1: start date+time, 2: site timezone. */
      __('Starts %1$s and has no end date. Times are in %2$s.', 'yayloyalty'),
      formatStoredMoment(startsAt),
      zoneLabel,
    );
  }

  return sprintf(
    /* translators: 1: end date+time (exclusive), 2: the last day the campaign actually runs, 3: site timezone. */
    __('Runs until %1$s, exclusive — ending as %2$s closes. Times are in %3$s.', 'yayloyalty'),
    formatStoredMoment(endsAt as string),
    formatDayBeforeStoredMoment(endsAt as string),
    zoneLabel,
  );
}

/* ─── §6 Priority ────────────────────────────────────────────────────── */

/** The priority number alone; where it lands among its group is the campaigns table's job. */
function CampaignEditorPriority() {
  const { control, setValue } = useFormContext<CampaignFormValues>();
  const priority = useWatch({ control, name: 'priority' });

  return (
    <Field orientation="responsive">
      <FieldLabel>{__('Priority', 'yayloyalty')}</FieldLabel>
      <FieldContent>
        <NumberInputRoot
          size="small"
          className="w-28"
          min={0}
          value={priority}
          onValueChange={(next) => setValue('priority', next, { shouldDirty: true })}
        >
          <NumberInputInput />
        </NumberInputRoot>
        <FieldDescription>
          {__(
            'Higher wins. Within the same group, the highest-priority match applies to an order and the rest are skipped — campaigns never stack.',
            'yayloyalty',
          )}
        </FieldDescription>
      </FieldContent>
    </Field>
  );
}
