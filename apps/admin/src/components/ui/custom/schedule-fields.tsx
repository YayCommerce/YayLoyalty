import { getSettings } from '@wordpress/date';
import { __, sprintf } from '@wordpress/i18n';

import {
  formatWPDate,
  formatWPTime,
  joinStoredDateTime,
  splitStoredDateTime,
  utcToDate,
} from '@/lib/helpers/format.helper';
import { Button } from '@/components/ui/button';
import { DatePicker } from '@/components/ui/date-picker';
import { Field, FieldContent, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';

/**
 * Start and end pickers for a nullable, exclusive-end UTC window (campaigns,
 * rewards), plus a sentence spelling out the exclusive end, which two date
 * fields cannot express. Values stay raw UTC strings; the pickers show
 * site-local time and convert at the inputs only.
 */
export function ScheduleFields({
  startsAt,
  endsAt,
  onStartsAtChange,
  onEndsAtChange,
  endsAtError,
}: {
  startsAt: string | null;
  endsAt: string | null;
  onStartsAtChange: (next: string | null) => void;
  onEndsAtChange: (next: string | null) => void;
  endsAtError?: { message?: string };
}) {
  const zoneLabel = siteTimezoneLabel();

  return (
    <div className="flex flex-col gap-4">
      <ScheduleBoundField label={__('Starts', 'yayloyalty')} value={startsAt} onChange={onStartsAtChange} />

      <div className="flex flex-col gap-1.5">
        <ScheduleBoundField label={__('Ends', 'yayloyalty')} value={endsAt} onChange={onEndsAtChange} />
        <FieldError errors={[endsAtError]} />
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
          {/* Keyed by `date`: DatePicker keeps an internal value while `value` is undefined, so a
              cleared field could redisplay a stale date. A fresh key remounts it. */}
          <DatePicker
            key={date}
            value={dateStringToLocalDate(date)}
            onValueChange={handleDateChange}
            placeholder={__('No date — always on', 'yayloyalty')}
          />
          {date && (
            <>
              <Input
                type="time"
                className="w-28"
                value={time}
                onChange={(event) => handleTimeChange(event.target.value)}
              />
              <Button type="button" variant="ghost" size="sm" onClick={() => onChange(null)}>
                {__('Clear', 'yayloyalty')}
              </Button>
            </>
          )}
        </div>
      </FieldContent>
    </Field>
  );
}

// UTC <-> site-local, converted at the picker

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

// date <-> Date, for the picker only; never a bare `new Date(str)`

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
  return `${formatWPDate(stored)} ${formatWPTime(stored)}`;
}

/**
 * The last day the window runs. ends_at is exclusive, so one day earlier
 * when it lands on a site-local midnight; a non-midnight end runs through
 * its own day. Subtracts 24h from the instant, not a calendar field, so the
 * result stays on the right site-local day.
 */
function formatDayBeforeStoredMoment(stored: string): string {
  const utcInstant = utcToDate(stored).getTime();
  const isSiteLocalMidnight = utcStringToSiteLocalParts(stored).time === '00:00';
  const lastDayInstant = isSiteLocalMidnight ? utcInstant - 24 * 60 * 60 * 1000 : utcInstant;

  return formatWPDate(new Date(lastDayInstant));
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

    return sprintf(
      /* translators: %s: a signed UTC offset, e.g. "+5:30" or "-5". */
      __('UTC%s', 'yayloyalty'),
      signed,
    );
  }

  if (typeof timezone?.offset === 'number' && timezone.offset !== 0) {
    const sign = timezone.offset > 0 ? '+' : '-';
    return sprintf(
      /* translators: 1: +/-, 2: hours offset from UTC. */
      __('UTC%1$s%2$s', 'yayloyalty'),
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
      /* translators: 1: start date+time, 2: end date+time (exclusive), 3: the last day it actually runs, 4: site timezone. */
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
    /* translators: 1: end date+time (exclusive), 2: the last day it actually runs, 3: site timezone. */
    __('Runs until %1$s, exclusive — ending as %2$s closes. Times are in %3$s.', 'yayloyalty'),
    formatStoredMoment(endsAt as string),
    formatDayBeforeStoredMoment(endsAt as string),
    zoneLabel,
  );
}
