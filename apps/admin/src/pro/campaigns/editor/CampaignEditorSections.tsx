import { ReactNode, useMemo, useState } from 'react';
import { ChevronDown, ChevronUp, TriangleAlert } from 'lucide-react';
import { Controller, useFormContext, useWatch } from 'react-hook-form';
import { getSettings } from '@wordpress/date';
import { __, sprintf } from '@wordpress/i18n';

import { joinStoredDateTime, parseWPDate, parseWPTime, splitStoredDateTime } from '@/lib/helpers/format.helper';
import { useCampaignsQuery } from '@/lib/queries/campaign.queries';
import { CampaignTrigger, LITE_CAMPAIGN_TRIGGERS, resolvePurchaseMode } from '@/lib/schema/campaign.schema';
import { cn } from '@/lib/utils';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { DatePicker } from '@/components/ui/date-picker';
import { Field, FieldContent, FieldDescription, FieldError, FieldLabel, FieldTitle } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { NumberInputInput, NumberInputRoot, NumberInputUnit } from '@/components/ui/number-input';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { Separator } from '@/components/ui/separator';
import { Skeleton } from '@/components/ui/skeleton';
import { Switch } from '@/components/ui/switch';
import { TagsInput } from '@/components/ui/tags-input';
import { statusLabel, triggerGroup, triggerLabel } from '../campaign-labels';
import { CampaignFormValues, useCampaignEditorContext } from './campaign-editor.form';
import { CampaignEditorConditions } from './CampaignEditorConditions';

/**
 * The editor's six sections, in the wireframe's order (see
 * docs/campaign-screens/YayLoyalty_Campaign_Editor.dc.html, section "1e"):
 * Basics, Trigger, Reward, Conditions, Schedule, Priority.
 *
 * All six render through the shared `CampaignEditorSection` shell (below).
 * Basics/Trigger/Reward/Schedule/Priority live in this file; Conditions is
 * the one section still in its own file (`CampaignEditorConditions.tsx`) —
 * it is the largest single concern (a `useFieldArray` builder with six
 * per-kind value editors) and earns that separation on its own, unlike the
 * others, which were single-caller files with little code each.
 */
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

/**
 * The wireframe's six numbered cards (docs/campaign-screens/
 * YayLoyalty_Campaign_Editor.dc.html, section "1e"): a circular step badge, a
 * title, and content. Not exported — verified via grep that nothing outside
 * this file ever imported it, so it stays a local implementation detail of
 * the section list above rather than a second public shell.
 */
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

/**
 * `CAMPAIGN_STATUSES` minus `archived` — archiving is its own flow (wireframe
 * §1g–1k, out of scope for this editor) and `Campaign::set_status()` throws
 * when archiving a built-in, so it is never an option this control offers.
 */
const EDITABLE_STATUSES = ['active', 'paused', 'draft'] as const;

/**
 * §1 Basics: `name` (required) and `status`. Two fields the wireframe drew
 * that don't survive contact with the schema are deliberately absent here —
 * see reference-wireframe-vs-schema.md §1:
 *  - "Internal notes": not a column, not a config key, not in the write args.
 *  - `slug`: shown read-only in `CampaignEditor.tsx`'s page description
 *    already (sourced from the loaded `Campaign`, never form state, since
 *    it's immutable after create) — not duplicated here.
 */
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
              // Empty string on re-click of the active item — never write that back, the
              // same guard the mode/base/rounding toggles below use.
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

/**
 * Per-trigger one-line descriptions. Kept local rather than added to
 * `campaign-labels.ts` (reused-not-modified for this phase) — the purchase
 * copy is deliberately soft: reference-wireframe-vs-schema.md §2 found no
 * order-status setting in `settings.schema.ts` to name, so this never claims
 * one exists (e.g. "Completed").
 */
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

/**
 * §2 Trigger — only the three LITE triggers (`LITE_CAMPAIGN_TRIGGERS`), even
 * though this editor is itself Pro-only: `CAMPAIGN_CONFIG_FIELDS`
 * (campaign.schema.ts) has no field map for any Pro trigger, so offering one
 * here would route straight into `RewardFieldsUnknown`'s read-only fallback —
 * not a real "create a Pro-trigger campaign" flow. Disabled regardless:
 * `update_item()` `unset()`s `trigger_type` before the row is written, so
 * this is immutable after create (annotation 1's "moves the row" claim is
 * false and not reproduced here — see reference-wireframe-vs-schema.md).
 *
 * Never a form field — sourced from the loaded `Campaign` via
 * `useCampaignEditorContext()`, per `campaign-editor.form.ts`'s docblock.
 */
function CampaignEditorTrigger() {
  const { campaign } = useCampaignEditorContext();

  // Downgrade safety: a stored trigger may fall outside this offerable list
  // (a Pro-trigger campaign, since only the three Lite triggers are offered
  // above — see this component's docblock). Always include the campaign's
  // own trigger so the control never renders with nothing selected.
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

/* ─── §3 Reward ──────────────────────────────────────────────────────── */

/**
 * §3 Reward — dispatches on the loaded campaign's trigger (never a form
 * field, see `campaign-editor.form.ts`) to the matching config renderer.
 * `purchase`/`signup`/`review` are the three triggers `CAMPAIGN_CONFIG_FIELDS`
 * (campaign.schema.ts) has a field map for; every other — every Pro trigger —
 * has no Lite field map and falls back to `RewardFieldsUnknown`, which must
 * not touch `config` (see that component's docblock below).
 */
function CampaignEditorReward() {
  const { campaign } = useCampaignEditorContext();

  switch (campaign.trigger) {
    case 'purchase':
      return <RewardFieldsPurchase />;
    case 'signup':
      return <RewardFieldsSignup />;
    case 'review':
      return <RewardFieldsReview />;
    default:
      return <RewardFieldsUnknown trigger={campaign.trigger} />;
  }
}

/** `config_schema()['purchase']` — mirrors `CAMPAIGN_CONFIG_FIELDS.purchase` (campaign.schema.ts). */
interface PurchaseConfig {
  points_per_unit?: number;
  mode?: 'rate' | 'multiplier';
  multiplier?: number;
  base?: 'after_discounts' | 'before_discounts';
  include_shipping?: boolean;
  include_tax?: boolean;
  rounding?: 'down' | 'nearest' | 'up';
  min_subtotal?: number;
  exclude_coupon_orders?: boolean;
  excluded_product_ids?: number[];
  excluded_category_ids?: number[];
  max_points_per_order?: number;
  daily_cap?: number;
}

/**
 * §3 Reward for `purchase` — mode choice plus an Advanced block holding every
 * remaining key.
 *
 * ANNOTATION 2 IS FALSE (reference-wireframe-vs-schema.md): multiplier mode
 * inherits ONLY `points_per_unit` from the default purchase campaign —
 * `PurchaseHandler::compute_points()` reads rounding/base/min_subtotal/
 * exclude_coupon_orders/include_tax/exclusions from THIS campaign's own
 * config regardless of mode. The Advanced block below therefore stays
 * rendered — never hidden — in both modes.
 */
function RewardFieldsPurchase() {
  const { watch, setValue } = useFormContext<CampaignFormValues>();
  const config = watch('config') as PurchaseConfig;
  const [advancedOpen, setAdvancedOpen] = useState(false);

  const mode = resolvePurchaseMode(config as Record<string, unknown>);
  const currency = window.yayLoyaltyMeta.wcMeta.currency_data.currency;

  // The `is_eligible()` dependency (annotation 2): a multiplier campaign needs the
  // default purchase campaign to exist AND be active, or it silently stops applying.
  // Same list query CampaignTable/BuiltInCampaignList already read, filtered here.
  const { data: campaignsData } = useCampaignsQuery({ perPage: 100 });
  const defaultCampaign = campaignsData?.data.find(
    (candidate) => candidate.is_builtin && candidate.trigger === 'purchase',
  );
  const defaultPointsPerUnit =
    typeof defaultCampaign?.config.points_per_unit === 'number' ? defaultCampaign.config.points_per_unit : undefined;

  function patchConfig(patch: Partial<PurchaseConfig>) {
    setValue('config', { ...config, ...patch }, { shouldDirty: true, shouldValidate: true });
  }

  const multiplier = config.multiplier ?? 1;
  const computedRate = defaultPointsPerUnit !== undefined ? multiplier * defaultPointsPerUnit : undefined;

  return (
    <div className="flex flex-col gap-4">
      {/*
        Two cards, one RadioGroup selecting `mode` — both NumberInputRoots stay mounted and
        bound to their OWN config key regardless of which is selected, so switching modes never
        clears the inactive one's stored value (Risk Assessment: "Mode toggle discards the
        inactive field's value"). Only the inactive card's input is disabled, not hidden.

        Each card is three stacked rows — radio + title, then the number input, then the
        description — rather than a label-wraps-content layout: the radio and its label sit in
        their own `FieldLabel` row so the number input and description aren't nested inside a
        `<label>` (still true to L5's "never a labelable control inside another control's label"
        fix), and the wider three-row shape reads more clearly than a cramped single-row card.
      */}
      <RadioGroup
        value={mode}
        onValueChange={(value) => value && patchConfig({ mode: value as PurchaseConfig['mode'] })}
        className="grid gap-3 sm:grid-cols-2"
      >
        <div className="has-data-[state=checked]:border-primary has-data-[state=checked]:bg-primary/5 dark:has-data-[state=checked]:bg-primary/10 flex flex-col gap-2.5 rounded-md border p-4">
          <FieldLabel htmlFor="reward-purchase-mode-multiplier" className="items-center">
            <RadioGroupItem id="reward-purchase-mode-multiplier" value="multiplier" />
            <FieldTitle>{__('Multiply the default rate', 'yayloyalty')}</FieldTitle>
          </FieldLabel>
          <NumberInputRoot
            size="small"
            className="w-28"
            min={0}
            decimalScale={2}
            disabled={mode !== 'multiplier'}
            value={config.multiplier ?? 0}
            onValueChange={(value) => patchConfig({ multiplier: value })}
          >
            <NumberInputInput id="reward-purchase-multiplier" />
            <NumberInputUnit unit="×" />
          </NumberInputRoot>
          <FieldDescription>
            {defaultPointsPerUnit !== undefined
              ? sprintf(
                  /* translators: 1: the multiplier, e.g. "2"; 2: the default rate, e.g. "1"; 3: currency code; 4: the resulting rate. */
                  __('%1$s × the default %2$s point(s) per 1 %3$s = %4$s point(s) per 1 %3$s.', 'yayloyalty'),
                  String(multiplier),
                  String(defaultPointsPerUnit),
                  currency,
                  String(computedRate),
                )
              : __('The default purchase campaign has no rate set yet.', 'yayloyalty')}
          </FieldDescription>
        </div>

        <div className="has-data-[state=checked]:border-primary has-data-[state=checked]:bg-primary/5 dark:has-data-[state=checked]:bg-primary/10 flex flex-col gap-2.5 rounded-md border p-4">
          {/* Missing `mode` means 'rate' (resolvePurchaseMode()) — this is that fallback. */}
          <FieldLabel htmlFor="reward-purchase-mode-rate" className="items-center">
            <RadioGroupItem id="reward-purchase-mode-rate" value="rate" />
            <FieldTitle>{__('Set its own rate', 'yayloyalty')}</FieldTitle>
          </FieldLabel>
          <NumberInputRoot
            size="small"
            className="w-28"
            min={0}
            decimalScale={2}
            disabled={mode !== 'rate'}
            value={config.points_per_unit ?? 0}
            onValueChange={(value) => patchConfig({ points_per_unit: value })}
          >
            <NumberInputInput id="reward-purchase-points-per-unit" />
            <NumberInputUnit unit={currency} />
          </NumberInputRoot>
          <FieldDescription>{sprintf(__('Points awarded per 1 %s spent.', 'yayloyalty'), currency)}</FieldDescription>
        </div>
      </RadioGroup>

      {mode === 'multiplier' && (!defaultCampaign || defaultCampaign.status !== 'active') && (
        <Alert variant="outline">
          <TriangleAlert />
          <AlertDescription>
            {__(
              'This campaign will not apply: multiplier mode needs the default purchase campaign to exist and be active.',
              'yayloyalty',
            )}
          </AlertDescription>
        </Alert>
      )}

      <Separator />

      <button
        type="button"
        onClick={() => setAdvancedOpen((open) => !open)}
        className="text-muted-foreground flex w-fit items-center gap-1.5 text-sm font-medium"
        aria-expanded={advancedOpen}
      >
        {advancedOpen ? <ChevronUp className="size-4" /> : <ChevronDown className="size-4" />}
        {__('Advanced', 'yayloyalty')}
      </button>

      {/* Every remaining `config_schema()['purchase']` key, visible in BOTH modes — see this
          component's docblock re: annotation 2. Compositions (NumberInput+unit, Segmented, Switch)
          lifted from BuiltInCampaignList.tsx's PurchaseFields; layout is ui/field.tsx stacked
          fields, not that file's fixed-width label grid (Key Insight in phase-04's doc). Each
          Switch field stacks its title above the control (default `Field` orientation) instead of
          the label-beside-switch `horizontal` orientation — the latter reads as cramped once
          several of these sit next to each other in this disclosure. */}
      {advancedOpen && (
        <div className="flex flex-col gap-4">
          <Field>
            <FieldLabel>{__('Base amount', 'yayloyalty')}</FieldLabel>
            <FieldContent>
              <Segmented
                value={config.base ?? 'after_discounts'}
                onValueChange={(value) => value && patchConfig({ base: value as PurchaseConfig['base'] })}
              >
                <SegmentedItem value="after_discounts">{__('After discounts', 'yayloyalty')}</SegmentedItem>
                <SegmentedItem value="before_discounts">{__('Before discounts', 'yayloyalty')}</SegmentedItem>
              </Segmented>
              <FieldDescription>
                {__(
                  'What the points are calculated on. After discounts means a coupon reduces the points too.',
                  'yayloyalty',
                )}
              </FieldDescription>
            </FieldContent>
          </Field>

          <Field>
            <FieldLabel>{__('Rounding', 'yayloyalty')}</FieldLabel>
            <FieldContent>
              <Segmented
                value={config.rounding ?? 'down'}
                onValueChange={(value) => value && patchConfig({ rounding: value as PurchaseConfig['rounding'] })}
              >
                <SegmentedItem value="down">{__('Down', 'yayloyalty')}</SegmentedItem>
                <SegmentedItem value="nearest">{__('Nearest', 'yayloyalty')}</SegmentedItem>
                <SegmentedItem value="up">{__('Up', 'yayloyalty')}</SegmentedItem>
              </Segmented>
            </FieldContent>
          </Field>

          <Field>
            <FieldLabel htmlFor="reward-purchase-include-tax">{__('Include tax', 'yayloyalty')}</FieldLabel>
            <Switch
              id="reward-purchase-include-tax"
              className="w-11!"
              checked={!!config.include_tax}
              onCheckedChange={(include_tax) => patchConfig({ include_tax })}
            />
          </Field>

          <Field>
            <FieldLabel htmlFor="reward-purchase-include-shipping">{__('Include shipping', 'yayloyalty')}</FieldLabel>
            <Switch
              id="reward-purchase-include-shipping"
              className="w-11!"
              checked={!!config.include_shipping}
              onCheckedChange={(include_shipping) => patchConfig({ include_shipping })}
            />
          </Field>
          <FieldDescription>
            {/* Stored but never read by BaseCalculator — still rendered so a downgrade/upgrade
                round trip doesn't lose it (reference-wireframe-vs-schema.md §3). */}
            {__(
              'Not currently used by point calculations — kept so saving this campaign never loses it.',
              'yayloyalty',
            )}
          </FieldDescription>

          <Field>
            <FieldLabel htmlFor="reward-purchase-min-subtotal">{__('Minimum order subtotal', 'yayloyalty')}</FieldLabel>
            <FieldContent>
              <NumberInputRoot
                size="small"
                className="w-32"
                min={0}
                decimalScale={2}
                fixedDecimalScale
                value={config.min_subtotal ?? 0}
                onValueChange={(min_subtotal) => patchConfig({ min_subtotal })}
              >
                <NumberInputInput id="reward-purchase-min-subtotal" />
                <NumberInputUnit unit={currency} />
              </NumberInputRoot>
              <FieldDescription>
                {__('Orders below this earn nothing. 0 means every order earns.', 'yayloyalty')}
              </FieldDescription>
            </FieldContent>
          </Field>

          <Field>
            <FieldLabel htmlFor="reward-purchase-exclude-coupon">
              {__('Exclude orders that used a coupon', 'yayloyalty')}
            </FieldLabel>
            <Switch
              id="reward-purchase-exclude-coupon"
              className="w-11!"
              checked={!!config.exclude_coupon_orders}
              onCheckedChange={(exclude_coupon_orders) => patchConfig({ exclude_coupon_orders })}
            />
          </Field>

          <Field>
            <FieldLabel htmlFor="reward-purchase-max-points">{__('Maximum points per order', 'yayloyalty')}</FieldLabel>
            <FieldContent>
              <NumberInputRoot
                size="small"
                className="w-32"
                min={0}
                value={config.max_points_per_order ?? 0}
                onValueChange={(max_points_per_order) => patchConfig({ max_points_per_order })}
              >
                <NumberInputInput id="reward-purchase-max-points" />
                <NumberInputUnit unit={__('points', 'yayloyalty')} />
              </NumberInputRoot>
              <FieldDescription>{__('0 means no per-order cap.', 'yayloyalty')}</FieldDescription>
            </FieldContent>
          </Field>

          <Field>
            <FieldLabel htmlFor="reward-purchase-daily-cap">{__('Daily cap', 'yayloyalty')}</FieldLabel>
            <FieldContent>
              <NumberInputRoot
                size="small"
                className="w-32"
                min={0}
                value={config.daily_cap ?? 0}
                onValueChange={(daily_cap) => patchConfig({ daily_cap })}
              >
                <NumberInputInput id="reward-purchase-daily-cap" />
                <NumberInputUnit unit={__('points', 'yayloyalty')} />
              </NumberInputRoot>
              <FieldDescription>{__('0 means no daily cap.', 'yayloyalty')}</FieldDescription>
            </FieldContent>
          </Field>

          {/* No product/category search endpoint exists — raw IDs via TagsInput rather than
              inventing one (Security Considerations / reference-wireframe-vs-schema.md §3). */}
          <Field>
            <FieldLabel>{__('Excluded products', 'yayloyalty')}</FieldLabel>
            <FieldContent>
              <TagsInput
                value={(config.excluded_product_ids ?? []).map(String)}
                onValueChange={(next) => patchConfig({ excluded_product_ids: toIntList(next) })}
                placeholder={__('Add a product ID and press Enter', 'yayloyalty')}
              />
              <FieldDescription>
                {__('Raw product IDs — there is no product search yet to pick these by name.', 'yayloyalty')}
              </FieldDescription>
            </FieldContent>
          </Field>

          <Field>
            <FieldLabel>{__('Excluded categories', 'yayloyalty')}</FieldLabel>
            <FieldContent>
              <TagsInput
                value={(config.excluded_category_ids ?? []).map(String)}
                onValueChange={(next) => patchConfig({ excluded_category_ids: toIntList(next) })}
                placeholder={__('Add a category ID and press Enter', 'yayloyalty')}
              />
              <FieldDescription>
                {__('Raw category IDs — there is no category search yet to pick these by name.', 'yayloyalty')}
              </FieldDescription>
            </FieldContent>
          </Field>
        </div>
      )}
    </div>
  );
}

// Ids are positive integers — same check `CampaignEditorConditions.tsx`'s `IntListEditor` uses
// for its own id-list `TagsInput` entries, so the two never disagree on what counts as a valid id.
function toIntList(values: string[]): number[] {
  return values.map((value) => Number.parseInt(value, 10)).filter((value) => Number.isInteger(value) && value > 0);
}

/** `config_schema()['signup']` — a single key. */
interface SignupConfig {
  points?: number;
}

function RewardFieldsSignup() {
  const { watch, setValue } = useFormContext<CampaignFormValues>();
  const config = watch('config') as SignupConfig;

  return (
    <Field>
      <FieldLabel htmlFor="reward-signup-points">{__('Award', 'yayloyalty')}</FieldLabel>
      <FieldContent>
        <NumberInputRoot
          size="small"
          className="w-28"
          min={0}
          value={config.points ?? 0}
          onValueChange={(points) =>
            setValue('config', { ...config, points }, { shouldDirty: true, shouldValidate: true })
          }
        >
          <NumberInputInput id="reward-signup-points" />
          <NumberInputUnit unit={__('points', 'yayloyalty')} />
        </NumberInputRoot>
        <FieldDescription>
          {__('Awarded once, the first time a customer creates an account.', 'yayloyalty')}
        </FieldDescription>
      </FieldContent>
    </Field>
  );
}

/** `config_schema()['review']`. */
interface ReviewConfig {
  points?: number;
  require_verified?: boolean;
  one_per_product?: boolean;
  daily_cap?: number;
  media_bonus_points?: number;
}

function RewardFieldsReview() {
  const { watch, setValue } = useFormContext<CampaignFormValues>();
  const config = watch('config') as ReviewConfig;

  function patchConfig(patch: Partial<ReviewConfig>) {
    setValue('config', { ...config, ...patch }, { shouldDirty: true, shouldValidate: true });
  }

  return (
    <>
      <Field>
        <FieldLabel htmlFor="reward-review-points">{__('Award', 'yayloyalty')}</FieldLabel>
        <FieldContent>
          <NumberInputRoot
            size="small"
            className="w-28"
            min={0}
            value={config.points ?? 0}
            onValueChange={(points) => patchConfig({ points })}
          >
            <NumberInputInput id="reward-review-points" />
            <NumberInputUnit unit={__('points', 'yayloyalty')} />
          </NumberInputRoot>
          <FieldDescription>{__('Per approved product review.', 'yayloyalty')}</FieldDescription>
        </FieldContent>
      </Field>

      <Field>
        {/* In config_schema() but absent from default_config() — default to 0 client-side
            rather than rendering undefined (reference-wireframe-vs-schema.md §3). */}
        <FieldLabel htmlFor="reward-review-media-bonus">{__('Media bonus', 'yayloyalty')}</FieldLabel>
        <FieldContent>
          <NumberInputRoot
            size="small"
            className="w-28"
            min={0}
            value={config.media_bonus_points ?? 0}
            onValueChange={(media_bonus_points) => patchConfig({ media_bonus_points })}
          >
            <NumberInputInput id="reward-review-media-bonus" />
            <NumberInputUnit unit={__('points', 'yayloyalty')} />
          </NumberInputRoot>
          <FieldDescription>
            {__('Extra points when the review includes a photo or video.', 'yayloyalty')}
          </FieldDescription>
        </FieldContent>
      </Field>

      {/* Stacked (default) orientation, matching the Advanced block's switches above — the same
          "title above, switch below" fix, applied consistently to every switch in this section. */}
      <Field>
        <FieldLabel htmlFor="reward-review-verified">{__('Verified purchases only', 'yayloyalty')}</FieldLabel>
        <Switch
          id="reward-review-verified"
          className='w-11!'
          checked={!!config.require_verified}
          onCheckedChange={(require_verified) => patchConfig({ require_verified })}
        />
      </Field>

      <Field>
        <FieldLabel htmlFor="reward-review-one-per-product">{__('One review per product', 'yayloyalty')}</FieldLabel>
        <Switch
          id="reward-review-one-per-product"
          className='w-11!'
          checked={!!config.one_per_product}
          onCheckedChange={(one_per_product) => patchConfig({ one_per_product })}
        />
      </Field>

      <Field>
        <FieldLabel htmlFor="reward-review-daily-cap">{__('Daily cap', 'yayloyalty')}</FieldLabel>
        <FieldContent>
          <NumberInputRoot
            size="small"
            className="w-32"
            min={0}
            value={config.daily_cap ?? 0}
            onValueChange={(daily_cap) => patchConfig({ daily_cap })}
          >
            <NumberInputInput id="reward-review-daily-cap" />
            <NumberInputUnit unit={__('points', 'yayloyalty')} />
          </NumberInputRoot>
          <FieldDescription>{__('0 means no daily cap.', 'yayloyalty')}</FieldDescription>
        </FieldContent>
      </Field>
    </>
  );
}

/**
 * Fallback for any trigger with no `CAMPAIGN_CONFIG_FIELDS` entry — every Pro
 * trigger today, since Lite has no schema for them and their keys arrive
 * through the `yayloyalty_campaign_config_whitelist` PHP filter, untyped.
 *
 * HIGHEST-VALUE CORRECTNESS DETAIL IN THIS PHASE: this component binds
 * NOTHING back to `config` — no `register`/`Controller`/`setValue` anywhere
 * in it, only a `watch()` for the read-only summary below.
 * `campaignToFormValues()` seeds form state with `structuredClone(campaign.config)`
 * verbatim, and because nothing here ever calls `setValue('config', …)`, that
 * clone is exactly what `formValuesToUpdateInput()` sends back on save —
 * byte-for-byte, whatever keys/types it holds. `validate_config()` preserves
 * an unknown trigger's config blob wholesale server-side specifically so a
 * Pro→Lite downgrade doesn't erase it; this component must not be the thing
 * that erases it on the client instead by only round-tripping the subset of
 * keys it happens to render controls for.
 */
function RewardFieldsUnknown({ trigger }: { trigger: CampaignTrigger }) {
  const { watch } = useFormContext<CampaignFormValues>();
  const config = watch('config');
  const entries = Object.entries(config ?? {});

  return (
    <div className="flex flex-col gap-3">
      <FieldDescription>
        {sprintf(
          /* translators: %s: the trigger's display label, e.g. "Birthday". */
          __(
            'This build has no editor for the "%s" trigger yet. Its settings are shown below for reference and are saved back unchanged.',
            'yayloyalty',
          ),
          triggerLabel(trigger),
        )}
      </FieldDescription>

      {entries.length === 0 ? (
        <p className="text-muted-foreground text-sm">{__('No settings stored.', 'yayloyalty')}</p>
      ) : (
        // Values are rendered as plain text nodes only — never dangerouslySetInnerHTML —
        // since these are campaign-supplied and untyped for this build (Security
        // Considerations: never render campaign-supplied strings as HTML).
        <dl className="flex flex-col gap-1.5 text-sm">
          {entries.map(([key, value]) => (
            <div key={key} className="flex items-baseline justify-between gap-3">
              <dt className="text-muted-foreground font-mono text-xs">{key}</dt>
              <dd className="text-right font-mono text-xs break-all">{formatUnknownConfigValue(value)}</dd>
            </div>
          ))}
        </dl>
      )}
    </div>
  );
}

function formatUnknownConfigValue(value: unknown): string {
  if (value === null || value === undefined) {
    return '—';
  }

  if (typeof value === 'object') {
    return JSON.stringify(value);
  }

  return String(value);
}

/* ─── §5 Schedule ────────────────────────────────────────────────────── */

/**
 * Section 5, Schedule. `starts_at`/`ends_at` are nullable, exclusive-end,
 * stored UTC `YYYY-MM-DD HH:mm:ss` strings (campaign.schema.ts) — form state
 * holds them raw and unconverted (code-standards.md §4); `splitStoredDateTime`/
 * `joinStoredDateTime` (phase-03) split/rejoin that raw string, still in UTC.
 *
 * The PICKER inputs are a second, separate conversion layer on top of that:
 * `includes/Utils/Dates.php`'s `to_site_tz()` documents that every stored
 * DATETIME is UTC and is converted to the site timezone only at display/input
 * edges — so what the merchant actually sees/types in the date+time fields
 * must be SITE-LOCAL, not raw UTC. `utcStringToSiteLocalParts`/
 * `siteLocalPartsToUtcString` below are that conversion, mirroring
 * `to_site_tz()`'s intent via `@wordpress/date`'s `getSettings().timezone`
 * offset. The underlying RHF form value stays the raw UTC string throughout —
 * conversion happens only in the picker's value/onChange derivation, never in
 * form state.
 *
 * `ends_at` being exclusive cannot be expressed by two date fields alone —
 * see the derived sentence below, the required deliverable this section
 * exists to get right (annotation 4 / phase-05's Risk Assessment).
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
  // Site-local, for display/editing — see this file's docblock. `onChange` below converts back
  // to the raw stored UTC string; the raw string itself is never touched here.
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
          <DatePicker
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
 * `getSettings().timezone.offset` — hours (fractional for e.g. `+5:30`) the
 * site's local time is ahead of UTC, mirroring `wp_timezone()`'s offset that
 * `Dates::to_site_tz()` (PHP) applies. A fixed offset, not full IANA/DST-aware
 * math — acceptable here per phase-05's fix guidance ("equivalent moment/
 * date-fns math using the site's UTC offset from `getSettings()`"); it can
 * disagree with `describeWindow()`'s `dateI18n`-based formatting (which IS
 * DST-aware for a named timezone) right around a DST transition, but agrees
 * everywhere else and keeps this section dependency-free.
 */
function getSiteOffsetMinutes(): number {
  const settings = getSettings() as unknown as { timezone?: { offset?: number } };
  const offsetHours = typeof settings.timezone?.offset === 'number' ? settings.timezone.offset : 0;

  return Math.round(offsetHours * 60);
}

/**
 * Converts a raw stored UTC `{ date, time }` pair to the site-local pair the
 * picker inputs show/accept. Pure offset arithmetic on UTC millisecond
 * components (never a bare `new Date(str)` — code-standards.md §4) —
 * `siteLocalPartsToUtcString` below is its exact inverse, so an unedited
 * round trip through both reproduces the original stored string exactly.
 */
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

/** The reverse of `utcStringToSiteLocalParts` — site-local `{ date, time }` back to the raw stored UTC string. */
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

/** Reads a millisecond timestamp's UTC calendar fields into a `{ date, time }` pair — no local-tz involvement. */
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
 * Builds a `Date` purely from a SITE-LOCAL `YYYY-MM-DD`'s own numeric
 * components (already converted by `utcStringToSiteLocalParts` above) —
 * never `new Date(dateString)`. That form parses as browser-local in most
 * engines and would silently shift the calendar day (code-standards.md §4).
 * Reading back only ever uses the LOCAL getters (`localDateToDateString`
 * below) that match how this Date was built, so this function itself does no
 * further timezone conversion — it is a plain day-of-the-month container for
 * the `DatePicker` widget, not a moment in time. The UTC<->site-tz conversion
 * happens once, above, before the string ever reaches here.
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

/** The reverse of `dateStringToLocalDate` — local getters only, matching how the Date was built. */
function localDateToDateString(date: Date | undefined): string {
  if (!date) {
    return '';
  }

  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');

  return `${year}-${month}-${day}`;
}

/**
 * For the derived sentence's formatted date+time. Builds a real UTC INSTANT
 * (`Date.UTC`, not browser-local components) from the raw stored value, then
 * hands it to `parseWPDate`/`parseWPTime` as-is — `@wordpress/date`'s
 * `dateI18n` (which those call) converts a real instant to the site timezone
 * itself via `moment(...).tz(...)`/`.utcOffset(...)`. Building the `Date` from
 * LOCAL components instead (the previous bug) already misrepresents the
 * instant before `dateI18n` gets to it, double-shifting the result by
 * (site offset − browser offset).
 */
function formatStoredMoment(stored: string): string {
  const { date, time } = splitStoredDateTime(stored);
  const [year, month, day] = date.split('-').map(Number);
  const [hour, minute] = (time || '00:00').split(':').map(Number);
  const utcInstant = new Date(Date.UTC(year, (month || 1) - 1, day || 1, hour || 0, minute || 0));

  return `${parseWPDate(utcInstant)} ${parseWPTime(utcInstant)}`;
}

/**
 * The last day the campaign actually runs. `ends_at` is exclusive, so this is
 * normally one calendar day before it — but only when it lands exactly on a
 * SITE-LOCAL midnight; an `ends_at` with a non-midnight local time (e.g.
 * `23:59`) genuinely runs through its own day, and should name that day, not
 * the one before it. Checked in site-local terms (`utcStringToSiteLocalParts`)
 * since that is what the merchant set and what `describeWindow()`'s sentence
 * is stated to be in. The day-before step subtracts a fixed 24h from the real
 * UTC instant (not from a calendar field) so the result still lands on the
 * correct site-local day once `parseWPDate` converts it.
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
 * `@wordpress/date`'s `getSettings().timezone` — its `string` field (an IANA
 * zone like `Europe/Oslo`) is only populated when the site sets a named
 * timezone rather than a bare UTC offset. Verified against no site-specific
 * assumption: fall through to the formatted/raw offset, and only as a last
 * resort a generic "UTC" label — never a fabricated city name (phase-05's
 * Risk Assessment: "`getSettings().timezone` unpopulated").
 *
 * WP core's `offsetFormatted` is UNSIGNED (e.g. `"5:30"` or `"-5"`) and
 * defaults to the string `"0"` for a manual-UTC-offset site with no actual
 * offset — a real, meaningful value, but indistinguishable here from "unset".
 * Treated as "no offset to show" (falls through to the bare "UTC" label)
 * rather than rendering the misleading "UTC0". A positive offset gets an
 * explicit `+` prefix; a negative one already carries its own `-` from WP
 * core, so it is used as-is, never double-signed.
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

/** The required deliverable: spells out the exclusive end in words (annotation 4). */
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

/**
 * Section 6, Priority. The number itself plus the derived ladder of every
 * other campaign sharing this one's trigger group (annotation 5) —
 * `useCampaignsQuery`, filtered by `triggerGroup()`, sorted priority desc
 * with ties to newest (`id` desc), matching `CampaignTable.tsx`'s ordering.
 *
 * No drag-reorder here: `CampaignTable`'s drag changes display order only
 * and never writes `priority` (see its `reorderGroup()`).
 */
function CampaignEditorPriority() {
  const { control, setValue } = useFormContext<CampaignFormValues>();
  const { campaign } = useCampaignEditorContext();
  const priority = useWatch({ control, name: 'priority' });
  const campaignsQuery = useCampaignsQuery({ perPage: 100 });

  const group = triggerGroup(campaign.trigger);

  const ladder = useMemo(() => {
    const rows = (campaignsQuery.data?.data ?? [])
      .filter((row) => triggerGroup(row.trigger) === group)
      // Reflects the unsaved priority being edited right now — every other row is a saved value
      // from the query, so this is the one row live-substituted, letting the ladder answer "what
      // would this reorder to" before the merchant saves.
      .map((row) => (row.id === campaign.id ? { ...row, priority: priority ?? row.priority } : row));

    return [...rows].sort((a, b) => b.priority - a.priority || b.id - a.id);
  }, [campaignsQuery.data, group, campaign.id, priority]);

  return (
    <div className="flex flex-col gap-4">
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

      {campaignsQuery.isPending && <Skeleton className="h-24 w-full" />}

      {!campaignsQuery.isPending && ladder.length > 0 && (
        <div className="flex flex-col gap-1.5 rounded-lg border p-3">
          {ladder.map((row) => {
            const isCurrent = row.id === campaign.id;

            return (
              <div
                key={row.id}
                className={cn('flex items-center justify-between gap-2 text-sm', isCurrent && 'font-semibold')}
              >
                <span className="flex items-center gap-2">
                  <span className="text-muted-foreground tabular-nums">{row.priority}</span>
                  {row.name}
                </span>
                {isCurrent ? (
                  <Badge variant="outline" className="uppercase">
                    {__('This campaign', 'yayloyalty')}
                  </Badge>
                ) : (
                  <span className="text-muted-foreground text-xs">
                    {rowOutranksCurrent(row.priority, row.id, priority ?? campaign.priority, campaign.id)
                      ? __('Wins over this', 'yayloyalty')
                      : __('Skipped when this matches', 'yayloyalty')}
                  </span>
                )}
              </div>
            );
          })}
          <p className="text-muted-foreground pt-1 text-xs">
            {__(
              'Campaigns do not stack. For a given order, only the highest-priority match in this group applies.',
              'yayloyalty',
            )}
          </p>
        </div>
      )}
    </div>
  );
}

/** Ties go to the newer campaign (higher id) — mirrors `CampaignTable.tsx`'s own tie handling. */
function rowOutranksCurrent(rowPriority: number, rowId: number, currentPriority: number, currentId: number): boolean {
  return rowPriority > currentPriority || (rowPriority === currentPriority && rowId > currentId);
}
