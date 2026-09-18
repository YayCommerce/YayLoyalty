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

/** The editor's six sections in order. Conditions lives in its own file; it is the one large enough to earn it. */
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

/**
 * Read-only trigger radio: trigger is immutable after create and renders
 * from the loaded Campaign, never form state. Offers only the Lite triggers,
 * since no Pro trigger has a field map.
 */
function CampaignEditorTrigger() {
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

/* ─── §3 Reward ──────────────────────────────────────────────────────── */

/** Dispatches on the loaded trigger; anything without a field map falls back to RewardFieldsUnknown. */
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

/** config_schema()['purchase']; mirrors CAMPAIGN_CONFIG_FIELDS.purchase. */
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
 * Mode choice plus an Advanced block. Multiplier mode inherits only
 * points_per_unit from the default campaign; rounding, base, minimum and
 * exclusions are always this campaign's own, so Advanced renders in both
 * modes.
 */
function RewardFieldsPurchase() {
  const { watch, setValue } = useFormContext<CampaignFormValues>();
  const config = watch('config') as PurchaseConfig;
  const [advancedOpen, setAdvancedOpen] = useState(false);

  const mode = resolvePurchaseMode(config as Record<string, unknown>);
  const currency = window.yayLoyaltyMeta.wcMeta.currency_data.currency;

  // A multiplier campaign silently stops applying unless the default purchase campaign exists
  // and is active.
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
      {/* Both inputs stay mounted and bound to their own key, so switching modes never clears
          the inactive value; the inactive input is disabled, not hidden. The radio and its label
          get their own row so no control nests inside another control's label. */}
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

      {/* Every remaining purchase key, visible in both modes. */}
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

// Same positive-integer check as IntListEditor, so the two agree on a valid id.
function toIntList(values: string[]): number[] {
  return values.map((value) => Number.parseInt(value, 10)).filter((value) => Number.isInteger(value) && value > 0);
}

/** config_schema()['signup']. */
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

/** config_schema()['review']. */
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

      <Field>
        <FieldLabel htmlFor="reward-review-verified">{__('Verified purchases only', 'yayloyalty')}</FieldLabel>
        <Switch
          id="reward-review-verified"
          className="w-11!"
          checked={!!config.require_verified}
          onCheckedChange={(require_verified) => patchConfig({ require_verified })}
        />
      </Field>

      <Field>
        <FieldLabel htmlFor="reward-review-one-per-product">{__('One review per product', 'yayloyalty')}</FieldLabel>
        <Switch
          id="reward-review-one-per-product"
          className="w-11!"
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
 * Fallback for a trigger with no field map (every Pro trigger). Binds
 * nothing back to config: the seeded clone must round-trip untouched, or
 * this would erase on the client what the server preserves for a downgrade.
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
        // Plain text nodes only; these values are campaign-supplied and untyped.
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

/**
 * The priority number plus the ladder of every campaign in the same trigger
 * group, ordered as CampaignTable orders them. No drag-reorder: the table's
 * drag never writes priority either.
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
      // Substitute the unsaved priority so the ladder answers "what would this reorder to".
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

/** Ties go to the newer campaign, matching CampaignTable. */
function rowOutranksCurrent(rowPriority: number, rowId: number, currentPriority: number, currentId: number): boolean {
  return rowPriority > currentPriority || (rowPriority === currentPriority && rowId > currentId);
}
