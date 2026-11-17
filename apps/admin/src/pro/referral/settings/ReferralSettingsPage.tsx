import { ReactNode } from 'react';
import { zodResolver } from '@hookform/resolvers/zod';
import { Controller, FieldPath, useForm, UseFormReturn } from 'react-hook-form';
import { toast } from 'sonner';

import { CAP, useCapability } from '@/lib/access';
import { toastApiError } from '@/lib/api/api';
import { storeCurrency } from '@/lib/helpers/format.helper';
import { useEditorSave } from '@/hooks/useEditorSave';
import { LoadingButton } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { AMOUNT_INPUT_WIDTH, MoneyInput } from '@/components/ui/custom/amount-inputs';
import { ErrorState } from '@/components/ui/custom/error-state';
import { UnsavedChangeDialog } from '@/components/ui/custom/unsaved-changed-dialog';
import {
  Field,
  FieldContent,
  FieldDescription,
  FieldError,
  FieldLabel,
  FieldLegend,
  FieldSet,
} from '@/components/ui/field';
import { NumberInputInput, NumberInputRoot, NumberInputUnit } from '@/components/ui/number-input';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Skeleton } from '@/components/ui/skeleton';
import { Switch } from '@/components/ui/switch';
import { REFERRAL_LIMITS, ReferralSettings } from '../referral-settings.schema';
import { useReferralSettingsQuery, useSaveReferralSettingsMutation } from '../referral.queries';
import { REFERRAL_SETTINGS_COPY as COPY } from './referral-settings.copy';
import {
  buildReferralSettingsUpdate,
  referralSettingsDefaults,
  referralSettingsFormSchema,
  ReferralSettingsFormValues,
} from './referral-settings.form';
import { ReferralOfferPreview } from './ReferralOfferPreview';

type Form = UseFormReturn<ReferralSettingsFormValues>;

function Section({ step, title, children }: { step: number; title: string; children: ReactNode }) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>{`${step} ${title}`}</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">{children}</CardContent>
    </Card>
  );
}

/** A whole-number field with a unit, its help text and its validation error. */
function NumberField(props: {
  form: Form;
  name: FieldPath<ReferralSettingsFormValues>;
  label: string;
  unit: string;
  min: number;
  max?: number;
  hint?: string;
}) {
  const { form, name, label, unit, min, max, hint } = props;
  const error = form.formState.errors[name as keyof ReferralSettingsFormValues];

  return (
    <Field>
      <FieldLabel htmlFor={`referral-${name}`}>{label}</FieldLabel>
      <FieldContent>
        <Controller
          control={form.control}
          name={name}
          render={({ field }) => (
            <NumberInputRoot
              size="small"
              className="w-fit max-w-full min-w-44"
              min={min}
              max={max}
              value={Number(field.value)}
              onValueChange={field.onChange}
            >
              <NumberInputInput id={`referral-${name}`} className="min-w-16" />
              <NumberInputUnit unit={unit} />
            </NumberInputRoot>
          )}
        />
        {hint && <FieldDescription>{hint}</FieldDescription>}
        <FieldError errors={[error]} />
      </FieldContent>
    </Field>
  );
}

function ReferralSettingsForm({ settings }: { settings: ReferralSettings }) {
  const canEdit = useCapability(CAP.MANAGE_CAMPAIGNS);
  const saveMutation = useSaveReferralSettingsMutation();
  const currency = storeCurrency();

  const form = useForm<ReferralSettingsFormValues>({
    resolver: zodResolver(referralSettingsFormSchema),
    values: referralSettingsDefaults(settings),
    resetOptions: { keepDirtyValues: true },
  });

  const { handleSave, leaveDialogProps } = useEditorSave(
    form,
    async (values) => {
      try {
        const saved = await saveMutation.mutateAsync(buildReferralSettingsUpdate(values, form.formState.dirtyFields));
        form.reset(referralSettingsDefaults(saved), { keepDirtyValues: false });
        toast.success(COPY.saved());
        return true;
      } catch (error) {
        await toastApiError(error, COPY.saveFailed());
        return false;
      }
    },
    { listPath: null },
  );

  const values = form.watch();
  const isPercent = values.discount_type === 'percent';

  return (
    <form onSubmit={handleSave} className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start">
      <fieldset disabled={!canEdit} className="flex min-w-0 flex-col gap-4">
        <Card>
          <CardContent>
            <Field orientation="horizontal">
              <FieldContent>
                <FieldLabel htmlFor="referral-enabled">{COPY.programLive()}</FieldLabel>
                <FieldDescription>{COPY.programLiveHint()}</FieldDescription>
              </FieldContent>
              <Controller
                control={form.control}
                name="enabled"
                render={({ field }) => (
                  <Switch
                    id="referral-enabled"
                    className="w-11!"
                    checked={field.value}
                    onCheckedChange={field.onChange}
                  />
                )}
              />
            </Field>
          </CardContent>
        </Card>

        <Section step={1} title={COPY.friendTitle()}>
          <Field>
            <FieldLabel htmlFor="referral-discount_amount">{COPY.discountLabel()}</FieldLabel>
            <FieldContent className="flex flex-col gap-3">
              <Controller
                control={form.control}
                name="discount_amount"
                render={({ field }) =>
                  isPercent ? (
                    <NumberInputRoot
                      size="small"
                      className={AMOUNT_INPUT_WIDTH}
                      min={0}
                      decimalScale={2}
                      value={field.value}
                      onValueChange={field.onChange}
                    >
                      <NumberInputInput id="referral-discount_amount" />
                      <NumberInputUnit unit="%" />
                    </NumberInputRoot>
                  ) : (
                    <MoneyInput
                      id="referral-discount_amount"
                      value={field.value}
                      currency={currency}
                      onValueChange={field.onChange}
                    />
                  )
                }
              />
              <FieldSet>
                <FieldLegend variant="label">{COPY.discountTypeLegend()}</FieldLegend>
                <Controller
                  control={form.control}
                  name="discount_type"
                  render={({ field }) => (
                    <RadioGroup className="flex gap-4" value={field.value} onValueChange={field.onChange}>
                      <label className="flex items-center gap-2 text-sm">
                        <RadioGroupItem value="fixed" />
                        {COPY.fixed()}
                      </label>
                      <label className="flex items-center gap-2 text-sm">
                        <RadioGroupItem value="percent" />
                        {COPY.percentage()}
                      </label>
                    </RadioGroup>
                  )}
                />
              </FieldSet>
              <FieldError errors={[form.formState.errors.discount_amount]} />
            </FieldContent>
          </Field>
          <Field>
            <FieldLabel htmlFor="referral-min_order">{COPY.minOrder()}</FieldLabel>
            <FieldContent>
              <Controller
                control={form.control}
                name="min_order"
                render={({ field }) => (
                  <MoneyInput
                    id="referral-min_order"
                    value={field.value}
                    currency={currency}
                    onValueChange={field.onChange}
                  />
                )}
              />
              <FieldDescription>{COPY.minOrderHint()}</FieldDescription>
            </FieldContent>
          </Field>
          <NumberField
            form={form}
            name="coupon_days"
            label={COPY.couponExpires()}
            unit={COPY.couponExpiresUnit()}
            min={1}
            max={REFERRAL_LIMITS.COUPON_DAYS_MAX}
          />
        </Section>

        <Section step={2} title={COPY.advocateTitle()}>
          <NumberField
            form={form}
            name="advocate_points"
            label={COPY.advocateTitle()}
            unit={COPY.advocateUnit()}
            min={1}
            hint={COPY.advocateHint()}
          />
        </Section>

        <Section step={3} title={COPY.payoutTitle()}>
          <NumberField
            form={form}
            name="hold_days"
            label={COPY.holdFor()}
            unit={COPY.holdUnit()}
            min={0}
            max={REFERRAL_LIMITS.HOLD_DAYS_MAX}
            hint={COPY.holdHint()}
          />
        </Section>

        <Section step={4} title={COPY.rulesTitle()}>
          <Field orientation="horizontal">
            <Controller
              control={form.control}
              name="exclude_other_coupons"
              render={({ field }) => (
                <Checkbox
                  id="referral-exclude_other_coupons"
                  checked={field.value}
                  onCheckedChange={(checked) => field.onChange(checked === true)}
                />
              )}
            />
            <FieldLabel htmlFor="referral-exclude_other_coupons">{COPY.excludeOtherCoupons()}</FieldLabel>
          </Field>
          <FieldDescription>{COPY.rulesNote()}</FieldDescription>
        </Section>

        <Section step={5} title={COPY.attributionTitle()}>
          <NumberField
            form={form}
            name="cookie_days"
            label={COPY.cookieWindow()}
            unit={COPY.cookieUnit()}
            min={1}
            max={REFERRAL_LIMITS.COOKIE_DAYS_MAX}
            hint={COPY.cookieHint()}
          />
        </Section>

        {canEdit && (
          <div className="flex items-center justify-end gap-3">
            <span className="text-muted-foreground text-sm">{COPY.saveHint()}</span>
            <LoadingButton
              type="submit"
              loading={saveMutation.isPending}
              disabled={!form.formState.isDirty || saveMutation.isPending}
            >
              {COPY.save()}
            </LoadingButton>
          </div>
        )}
      </fieldset>

      <ReferralOfferPreview values={values} />
      <UnsavedChangeDialog {...leaveDialogProps} />
    </form>
  );
}

/** Referral → Settings: the program switch, what each side gets, the hold, the rules and the cookie window. */
export function ReferralSettingsPage() {
  const query = useReferralSettingsQuery();

  if (query.isPending) {
    return <Skeleton className="h-96 w-full" />;
  }

  if (query.isError) {
    return <ErrorState message={COPY.loadFailed()} onRetry={() => query.refetch()} />;
  }

  return <ReferralSettingsForm settings={query.data} />;
}
