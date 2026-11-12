import { ReactNode, useEffect, useState } from 'react';
import { InfoIcon, Package, TriangleAlert } from 'lucide-react';
import { FieldError as HookFieldError, useFormContext, useWatch } from 'react-hook-form';
import { __, _n, sprintf } from '@wordpress/i18n';

import { numberConfig } from '@/lib/helpers/reward.helper';
import { useProductSummaryQuery } from '@/lib/queries/lookup.queries';
import { isCatalogRewardType, RewardType } from '@/lib/schema/reward.schema';
import { cn } from '@/lib/utils';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { MoneyInput, OptionalMoneyInput, PointsInput } from '@/components/ui/custom/amount-inputs';
import { EditorSection } from '@/components/ui/custom/editor-section';
import { ErrorState } from '@/components/ui/custom/error-state';
import { LookupMultiSelect } from '@/components/ui/custom/lookup-multi-select';
import { Field, FieldContent, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { NumberInputInput, NumberInputRoot, NumberInputUnit } from '@/components/ui/number-input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Switch } from '@/components/ui/switch';
import { FlexibleDiscountConfig, FlexibleDiscountFields } from '@/pages/rewards/FlexibleDiscountFields';
import { rewardCouponCodeCopy } from '@/pages/rewards/reward-coupon-code.copy';
import { TierBadge } from '../../tiers/TierBadge';
import { useTiersEnabled, useTiersQuery } from '../../tiers/tiers.queries';
import { RewardFormValues, TierRule } from './reward-editor.form';

/**
 * The editor's sections for one reward type: the built-in's rate settings, or a catalog reward's cost and value.
 * Status and schedule live in the side area. `couponCode` is the saved reward's, absent while creating.
 */
export function RewardEditorSections({ type, couponCode }: { type: RewardType; couponCode?: string }) {
  const isCatalog = isCatalogRewardType(type);

  return (
    <div className="flex flex-col gap-4">
      <EditorSection contentClassName="gap-5" step={1} title={__('Basics', 'yayloyalty')}>
        <RewardEditorBasics />
        {couponCode && <RewardEditorCouponCode code={couponCode} />}
      </EditorSection>

      <EditorSection
        contentClassName="gap-5"
        step={2}
        title={isCatalog ? __('Cost and value', 'yayloyalty') : __('The rate', 'yayloyalty')}
      >
        {isCatalog ? <RewardEditorValue type={type} /> : <RewardEditorFlexibleDiscount />}
      </EditorSection>

      {(type === 'fixed_coupon' || type === 'percent_coupon') && (
        <EditorSection contentClassName="gap-5" step={3} title={__('Tier', 'yayloyalty')}>
          <RewardEditorTier />
        </EditorSection>
      )}
    </div>
  );
}

/* ─── form access ────────────────────────────────────────────────────── */

const EDIT = { shouldDirty: true, shouldValidate: true } as const;

/** The live config plus a merge-patch writer; config is one form field, so every key edit replaces it whole. */
function useRewardConfig() {
  const { control, setValue, formState } = useFormContext<RewardFormValues>();
  const config = useWatch({ control, name: 'config' });

  function patchConfig(next: Record<string, unknown>) {
    setValue('config', { ...config, ...next }, EDIT);
  }

  // RHF types errors.config by the loose record; the per-type schema reports per key.
  const configErrors = (formState.errors.config ?? {}) as Record<string, HookFieldError | undefined>;

  return { config, patchConfig, configErrors };
}

// Basics

function RewardEditorBasics() {
  const { register, formState } = useFormContext<RewardFormValues>();

  return (
    <Field orientation="responsive">
      <FieldLabel htmlFor="reward-basics-name">{__('Name', 'yayloyalty')}</FieldLabel>
      <FieldContent>
        <Input id="reward-basics-name" aria-invalid={!!formState.errors.name} {...register('name')} />
        <FieldError errors={[formState.errors.name]} />
        <FieldDescription>
          {__('Customers see this name in the launcher and in My Account.', 'yayloyalty')}
        </FieldDescription>
      </FieldContent>
    </Field>
  );
}

function RewardEditorCouponCode({ code }: { code: string }) {
  const copy = rewardCouponCodeCopy();

  return (
    <Field orientation="responsive">
      <FieldLabel htmlFor="reward-basics-coupon-code">{copy.label}</FieldLabel>
      <FieldContent>
        <Input id="reward-basics-coupon-code" value={code} readOnly className="font-mono" />
        <FieldDescription>{copy.description}</FieldDescription>
      </FieldContent>
    </Field>
  );
}

// Cost and value

function RewardEditorFlexibleDiscount() {
  const { config, patchConfig } = useRewardConfig();

  return (
    <FlexibleDiscountFields
      value={config as unknown as FlexibleDiscountConfig}
      onChange={(next) => patchConfig(next as Record<string, unknown>)}
    />
  );
}

function RewardEditorValue({ type }: { type: RewardType }) {
  const { control, setValue, formState } = useFormContext<RewardFormValues>();
  const costPoints = useWatch({ control, name: 'cost_points' });
  const { config, patchConfig, configErrors } = useRewardConfig();
  const currency = String(config.currency ?? '');

  return (
    <>
      {type === 'free_product' && <RewardEditorProduct />}

      <RewardField
        label={__('Costs the customer', 'yayloyalty')}
        error={formState.errors.cost_points}
        description={__('Points are spent when the customer claims the reward.', 'yayloyalty')}
      >
        <PointsInput value={costPoints ?? 0} min={0} onValueChange={(next) => setValue('cost_points', next, EDIT)} />
      </RewardField>

      {type === 'fixed_coupon' && (
        <RewardField label={__('Discount amount', 'yayloyalty')} error={configErrors.amount}>
          <MoneyInput
            value={numberConfig(config, 'amount') ?? 0}
            currency={currency}
            onValueChange={(amount) => patchConfig({ amount })}
          />
        </RewardField>
      )}

      {type === 'percent_coupon' && (
        <>
          <RewardField
            label={__('Discount', 'yayloyalty')}
            error={configErrors.percent}
            description={__('Off the order subtotal. Shipping and tax are excluded.', 'yayloyalty')}
          >
            <NumberInputRoot
              size="small"
              className="w-28"
              min={0}
              max={100}
              value={numberConfig(config, 'percent') ?? 0}
              onValueChange={(percent) => patchConfig({ percent })}
            >
              <NumberInputInput />
              <NumberInputUnit unit="%" />
            </NumberInputRoot>
          </RewardField>
          <RewardField
            label={__('Cap the discount at', 'yayloyalty')}
            error={configErrors.max_discount}
            description={__(
              'Without a cap a percentage reward is unbounded on a large order. Leave empty for no cap.',
              'yayloyalty',
            )}
          >
            <OptionalMoneyInput
              value={numberConfig(config, 'max_discount')}
              currency={currency}
              onValueChange={(max_discount) => patchConfig({ max_discount })}
            />
          </RewardField>
        </>
      )}

      {type !== 'free_product' && (
        <RewardField
          label={__('Minimum order', 'yayloyalty')}
          error={configErrors.min_order}
          description={__('The coupon will not apply below this. Leave empty for no minimum.', 'yayloyalty')}
        >
          <OptionalMoneyInput
            value={numberConfig(config, 'min_order')}
            currency={currency}
            placeholder={__('No minimum', 'yayloyalty')}
            onValueChange={(min_order) => patchConfig({ min_order })}
          />
        </RewardField>
      )}

      {(type === 'fixed_coupon' || type === 'percent_coupon') && (
        <RewardField
          label={__('Exclude sale items', 'yayloyalty')}
          error={configErrors.exclude_sale_items}
          description={__('The coupon is refused on orders containing discounted products.', 'yayloyalty')}
        >
          <Switch
            className="mt-2"
            checked={config.exclude_sale_items === true}
            onCheckedChange={(exclude_sale_items) => patchConfig({ exclude_sale_items })}
          />
        </RewardField>
      )}
    </>
  );
}

function RewardEditorProduct() {
  const { config, patchConfig, configErrors } = useRewardConfig();
  const productId = typeof config.product_id === 'number' ? config.product_id : null;
  const qty = numberConfig(config, 'qty') ?? 1;
  const [isChoosing, setIsChoosing] = useState(false);
  const showPicker = productId === null || isChoosing;

  function choose(ids: number[]) {
    if (ids.length === 0) {
      return;
    }

    patchConfig({ product_id: ids[ids.length - 1], variation_id: null });
    setIsChoosing(false);
  }

  return (
    <>
      <RewardField label={__('Product', 'yayloyalty')} error={configErrors.product_id}>
        {showPicker ? (
          <div className="flex items-center gap-2">
            <div className="min-w-0 flex-1">
              {/* Empty on purpose: the picker only ever adds the one product that replaces the current pick. */}
              <LookupMultiSelect
                entity="products"
                value={[]}
                onChange={choose}
                placeholder={__('Search products', 'yayloyalty')}
              />
            </div>
            {productId !== null && (
              <Button type="button" variant="link" size="sm" onClick={() => setIsChoosing(false)}>
                {__('Cancel', 'yayloyalty')}
              </Button>
            )}
          </div>
        ) : (
          <RewardProductCard productId={productId} onChange={() => setIsChoosing(true)} />
        )}
      </RewardField>
      <RewardField label={__('Quantity per claim', 'yayloyalty')} error={configErrors.qty}>
        <NumberInputRoot
          size="small"
          className="w-28"
          min={1}
          value={qty}
          onValueChange={(next) => patchConfig({ qty: next })}
        >
          <NumberInputInput />
          <NumberInputUnit unit={_n('item', 'items', qty, 'yayloyalty')} />
        </NumberInputRoot>
      </RewardField>
    </>
  );
}

/** The chosen product as the merchant knows it from WooCommerce: thumbnail, name, SKU · price · categories, live stock. */
function RewardProductCard({ productId, onChange }: { productId: number; onChange: () => void }) {
  const { data: product, isPending, isError } = useProductSummaryQuery(productId);

  const changeButton = (
    <Button type="button" variant="outline" size="sm" className="shrink-0" onClick={onChange}>
      {isError ? __('Choose another product', 'yayloyalty') : __('Change product', 'yayloyalty')}
    </Button>
  );

  if (isPending) {
    return (
      <div className="flex items-center gap-4 rounded-lg border p-3">
        <Skeleton className="size-14 shrink-0 rounded-md" />
        <div className="flex flex-1 flex-col gap-2">
          <Skeleton className="h-4 w-48" />
          <Skeleton className="h-3 w-64" />
        </div>
        <Skeleton className="h-8 w-24" />
      </div>
    );
  }

  if (isError) {
    return (
      <div className="border-destructive/40 bg-destructive/5 flex flex-wrap items-center gap-4 rounded-lg border p-3">
        <ProductThumbnail url={null} />
        <p className="text-destructive min-w-0 flex-1 text-sm">
          {__(
            'This product no longer exists or could not be loaded. Customers cannot claim it until you choose another one.',
            'yayloyalty',
          )}
        </p>
        {changeButton}
      </div>
    );
  }

  const stock = productStockLabel(product.stock_status, product.stock_quantity);
  const details = [
    product.sku && sprintf(/* translators: %s: product SKU. */ __('SKU %s', 'yayloyalty'), product.sku),
    product.price,
    product.category_names,
  ]
    .filter(Boolean)
    .join(' · ');

  return (
    <div className="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-lg border p-3">
      <ProductThumbnail url={product.image_url} />
      <div className="flex min-w-0 flex-1 flex-col gap-0.5">
        <span className="truncate text-sm font-semibold">{product.name}</span>
        {details && <span className="text-muted-foreground truncate text-xs">{details}</span>}
      </div>
      <div className="flex shrink-0 flex-col items-end gap-0.5 text-right">
        <span className={cn('text-sm font-semibold tabular-nums', !stock.isAvailable && 'text-destructive')}>
          {stock.label}
        </span>
        <span className="text-muted-foreground text-xs">
          {product.stock_quantity === null
            ? __('Stock not tracked', 'yayloyalty')
            : __('WooCommerce inventory', 'yayloyalty')}
        </span>
      </div>
      {changeButton}
    </div>
  );
}

function ProductThumbnail({ url }: { url: string | null }) {
  return url ? (
    <img src={url} alt="" className="size-14 shrink-0 rounded-md border object-cover" />
  ) : (
    <div className="bg-muted text-muted-foreground flex size-14 shrink-0 items-center justify-center rounded-md border">
      <Package className="size-5" aria-hidden />
    </div>
  );
}

/** WooCommerce's stock state in words; a tracked count wins over the bare status while there is stock. */
function productStockLabel(status: string, quantity: number | null): { label: string; isAvailable: boolean } {
  if (status === 'outofstock') {
    return { label: __('Out of stock', 'yayloyalty'), isAvailable: false };
  }

  if (status === 'onbackorder') {
    return { label: __('On backorder', 'yayloyalty'), isAvailable: true };
  }

  if (quantity !== null) {
    return {
      /* translators: %d: units in stock. */
      label: sprintf(_n('%d in stock', '%d in stock', quantity, 'yayloyalty'), quantity),
      isAvailable: quantity > 0,
    };
  }

  return { label: __('In stock', 'yayloyalty'), isAvailable: true };
}

// Tier

const TIER_RULE_LABELS: Record<TierRule, string> = {
  any: __('Any tier', 'yayloyalty'),
  gte: __('Minimum tier', 'yayloyalty'),
  eq: __('Only this tier', 'yayloyalty'),
};

const TIER_RULE_DESCRIPTIONS: Partial<Record<TierRule, string>> = {
  gte: __('Only customers in this tier or a higher one see and can claim this reward.', 'yayloyalty'),
  eq: __('Only customers in this tier see and can claim this reward.', 'yayloyalty'),
};

function RewardEditorTier() {
  const { control, setValue, formState } = useFormContext<RewardFormValues>();
  const tierRule = useWatch({ control, name: 'tier_rule' });
  const tierId = useWatch({ control, name: 'tier_id' });
  const tiersQuery = useTiersQuery();
  const tiersEnabled = useTiersEnabled();
  const tiers = tiersQuery.data;
  const isRemoved = tiers !== undefined && tierId !== null && !tiers.some((tier) => tier.id === tierId);
  const storedId = formState.defaultValues?.tier_id ?? null;
  const wasRemoved = tiers !== undefined && storedId !== null && !tiers.some((tier) => tier.id === storedId);
  const description = TIER_RULE_DESCRIPTIONS[tierRule];

  // The server refuses a save naming a deleted tier, so a removed one is cleared; dirtying it lets Save write that.
  useEffect(() => {
    if (isRemoved) {
      setValue('tier_rule', 'any', EDIT);
      setValue('tier_id', null, EDIT);
    }
  }, [isRemoved, setValue]);

  const changeRule = (next: TierRule) => {
    setValue('tier_rule', next, EDIT);

    if (next === 'any') {
      setValue('tier_id', null, EDIT);
    }
  };

  return (
    <>
      {!tiersEnabled && (
        <Alert variant="outline">
          <InfoIcon />
          <AlertDescription>
            {__(
              'Tiers are off. Rewards that require a tier are hidden from every customer until you turn tiers on.',
              'yayloyalty',
            )}
          </AlertDescription>
        </Alert>
      )}

      <Field orientation="responsive">
        <FieldLabel htmlFor="reward-tier-rule">{__('Tier rule', 'yayloyalty')}</FieldLabel>
        <FieldContent>
          <Select
            value={tierRule}
            onValueChange={(next) => changeRule(next as TierRule)}
            disabled={tiersQuery.isPending || tiersQuery.isError}
          >
            <SelectTrigger id="reward-tier-rule" className="w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {(Object.keys(TIER_RULE_LABELS) as TierRule[]).map((rule) => (
                <SelectItem key={rule} value={rule}>
                  {TIER_RULE_LABELS[rule]}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          {tiersQuery.isError && (
            <ErrorState
              message={__('Could not load the tiers.', 'yayloyalty')}
              onRetry={() => void tiersQuery.refetch()}
            />
          )}
          {wasRemoved && tierRule === 'any' && (
            <Alert variant="warning">
              <TriangleAlert />
              <AlertDescription>{__('The tier this reward required was removed.', 'yayloyalty')}</AlertDescription>
            </Alert>
          )}
        </FieldContent>
      </Field>

      {tierRule !== 'any' && (
        <Field orientation="responsive">
          <FieldLabel htmlFor="reward-tier-id">{__('Tier', 'yayloyalty')}</FieldLabel>
          <FieldContent>
            <Select
              value={tierId === null ? '' : String(tierId)}
              onValueChange={(next) => setValue('tier_id', Number(next), EDIT)}
              disabled={tiersQuery.isPending || tiersQuery.isError}
            >
              <SelectTrigger id="reward-tier-id" className="w-full" aria-invalid={!!formState.errors.tier_id}>
                <SelectValue placeholder={__('Select a tier', 'yayloyalty')} />
              </SelectTrigger>
              <SelectContent>
                {tiers?.map((tier) => (
                  <SelectItem key={tier.id} value={String(tier.id)}>
                    <TierBadge name={tier.name} color={tier.color} />
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <FieldError errors={[formState.errors.tier_id]} />
            {description && <FieldDescription>{description}</FieldDescription>}
          </FieldContent>
        </Field>
      )}
    </>
  );
}

/* ─── field atoms ────────────────────────────────────────────────────── */

function RewardField({
  label,
  description,
  error,
  children,
}: {
  label: string;
  description?: string;
  error?: { message?: string };
  children: ReactNode;
}) {
  return (
    <Field orientation="responsive">
      <FieldLabel>{label}</FieldLabel>
      <FieldContent>
        {children}
        <FieldError errors={[error]} />
        {description && <FieldDescription>{description}</FieldDescription>}
      </FieldContent>
    </Field>
  );
}
