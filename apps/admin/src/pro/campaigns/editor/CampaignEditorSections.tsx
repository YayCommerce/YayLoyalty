import { ReactNode } from 'react';
import { Controller, useFormContext, useWatch } from 'react-hook-form';
import { __ } from '@wordpress/i18n';

import { CampaignTrigger, LITE_CAMPAIGN_TRIGGERS, LiteCampaignTrigger } from '@/lib/schema/campaign.schema';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ScheduleFields } from '@/components/ui/custom/schedule-fields';
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

function CampaignEditorSchedule() {
  const { control, setValue, formState } = useFormContext<CampaignFormValues>();
  const startsAt = useWatch({ control, name: 'starts_at' });
  const endsAt = useWatch({ control, name: 'ends_at' });

  return (
    <ScheduleFields
      startsAt={startsAt}
      endsAt={endsAt}
      onStartsAtChange={(next) => setValue('starts_at', next, { shouldDirty: true, shouldValidate: true })}
      onEndsAtChange={(next) => setValue('ends_at', next, { shouldDirty: true, shouldValidate: true })}
      endsAtError={formState.errors.ends_at}
    />
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
