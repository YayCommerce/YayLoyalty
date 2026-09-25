import { useEffect } from 'react';
import { PlusIcon, XIcon } from 'lucide-react';
import { useController, useFieldArray, useFormContext, useWatch } from 'react-hook-form';
import { __, sprintf } from '@wordpress/i18n';

import { countryOptions, currencyOptions, paymentGatewayOptions, roleOptions } from '@/lib/helpers/meta-options.helper';
import {
  CAMPAIGN_CONDITION_SCHEMA,
  CAMPAIGN_CONDITION_TYPES,
  CampaignCondition,
  CampaignConditionKind,
} from '@/lib/schema/campaign.schema';
import { LookupEntity } from '@/lib/schema/lookup.schema';
import { Button } from '@/components/ui/button';
import { LookupMultiSelect } from '@/components/ui/custom/lookup-multi-select';
import { MultiSelectComboboxOption } from '@/components/ui/custom/multi-select-combobox';
import { OptionListSelect } from '@/components/ui/custom/option-list-select';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { FieldError } from '@/components/ui/field';
import { NumberInputInput, NumberInputRoot, NumberInputUnit } from '@/components/ui/number-input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { conditionTypeLabel } from '../campaign-labels';
import { CampaignFormValues } from './campaign-editor.form';

/**
 * Conditions section: a useFieldArray over a flat, ANDed list with at most
 * one entry per type. Per-type value validation runs in the editor's
 * resolver (mergeConditionErrors), not here, so it covers every save path.
 */
export function CampaignEditorConditions() {
  const { control } = useFormContext<CampaignFormValues>();
  const { fields, append, remove } = useFieldArray({ control, name: 'conditions' });

  const usedTypes = new Set(fields.map((field) => field.type));

  function handleAdd(type: CampaignConditionKind) {
    append({
      type,
      operator: firstOperatorFor(type),
      value: defaultConditionValue(type),
    } satisfies CampaignCondition);
  }

  return (
    <div className="flex flex-col gap-4">
      {fields.length === 0 ? (
        <Empty className="py-8">
          <EmptyHeader>
            <EmptyTitle>{__('No conditions — this applies to every order in the date range', 'yayloyalty')}</EmptyTitle>
            <EmptyDescription>
              {__('Add one to narrow it, for example to a country or a minimum lifetime spend.', 'yayloyalty')}
            </EmptyDescription>
          </EmptyHeader>
        </Empty>
      ) : (
        <div className="flex flex-col gap-3">
          {fields.map((field, index) => (
            <ConditionRow key={field.id} index={index} type={field.type} onRemove={() => remove(index)} />
          ))}
        </div>
      )}

      <div className="flex flex-col gap-1.5">
        <div className="flex flex-wrap items-center gap-2">
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button type="button" variant="outline" size="sm">
                <PlusIcon className="size-4" />
                {__('Add condition', 'yayloyalty')}
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start">
              {CAMPAIGN_CONDITION_TYPES.map((type) => (
                <DropdownMenuItem key={type} disabled={usedTypes.has(type)} onSelect={() => handleAdd(type)}>
                  {conditionTypeLabel(type)}
                </DropdownMenuItem>
              ))}
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
        <p className="text-muted-foreground text-xs">
          {__(
            'Conditions are combined with AND. A campaign that needs OR is two campaigns at the same priority.',
            'yayloyalty',
          )}
        </p>
      </div>
    </div>
  );
}

/* ─── one condition's row ────────────────────────────────────────────── */

/** Merchant-facing labels for every operator CAMPAIGN_CONDITION_SCHEMA recognises. */
const OPERATOR_LABELS: Record<string, string> = {
  in: __('is any of', 'yayloyalty'),
  not_in: __('is none of', 'yayloyalty'),
  gte: __('at least', 'yayloyalty'),
  lte: __('at most', 'yayloyalty'),
  gt: __('more than', 'yayloyalty'),
  lt: __('less than', 'yayloyalty'),
  eq: __('exactly', 'yayloyalty'),
  between: __('between', 'yayloyalty'),
};

/** One condition row. The type is fixed once added; only operator and value change. */
function ConditionRow({ index, type, onRemove }: { index: number; type: CampaignConditionKind; onRemove: () => void }) {
  const { control, setValue, getValues, formState, clearErrors } = useFormContext<CampaignFormValues>();
  const spec = CAMPAIGN_CONDITION_SCHEMA[type];
  const operator = useWatch({ control, name: `conditions.${index}.operator` });
  const condition = useWatch({ control, name: `conditions.${index}` });
  const error = formState.errors.conditions?.[index]?.value;

  // A resolver error would otherwise stay until the next submit; clear it as soon as the row is valid.
  useEffect(() => {
    if (error && !validateConditionValue(condition)) {
      clearErrors(`conditions.${index}.value` as `conditions.${number}.value`);
    }
  }, [condition, error, index, clearErrors]);

  function handleOperatorChange(nextOperator: string) {
    const currentValue = getValues(`conditions.${index}.value`);
    const reshaped = reshapeConditionValue(spec.value, currentValue, operator, nextOperator);

    setValue(`conditions.${index}.operator`, nextOperator, { shouldDirty: true });
    setValue(`conditions.${index}.value`, reshaped, { shouldDirty: true });
  }

  return (
    <div className="flex flex-col gap-2 rounded-lg border p-3">
      <div className="flex flex-wrap items-center gap-2">
        <span className="w-36 shrink-0 text-sm font-medium">{conditionTypeLabel(type)}</span>

        <Select value={operator} onValueChange={handleOperatorChange}>
          <SelectTrigger size="sm" className="w-36 shrink-0">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {spec.operators.map((op) => (
              <SelectItem key={op} value={op}>
                {OPERATOR_LABELS[op] ?? op}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        <div className="min-w-56 flex-1">
          <ConditionValueEditor index={index} type={type} operator={operator} />
        </div>

        <Button
          type="button"
          variant="ghost"
          size="icon-sm"
          aria-label={__('Remove condition', 'yayloyalty')}
          onClick={onRemove}
        >
          <XIcon className="size-4" />
        </Button>
      </div>

      <FieldError errors={[error]} />
    </div>
  );
}

/**
 * One editor per condition type. Lists come from pickers, never free text:
 * roles, gateways, currencies and countries from the bootstrap's own
 * vocabularies, customers, products and categories through the lookup
 * route. The numeric kinds take `operator`, where `between` means a pair.
 */
function ConditionValueEditor({
  index,
  type,
  operator,
}: {
  index: number;
  type: CampaignConditionKind;
  operator: string;
}) {
  switch (type) {
    case 'user_role':
      return <OptionListEditor index={index} options={roleOptions()} placeholder={__('Choose roles', 'yayloyalty')} />;
    case 'payment_method':
      return (
        <OptionListEditor
          index={index}
          options={paymentGatewayOptions()}
          placeholder={__('Choose payment methods', 'yayloyalty')}
        />
      );
    case 'currency':
      return (
        <OptionListEditor
          index={index}
          options={currencyOptions()}
          placeholder={__('Choose currencies', 'yayloyalty')}
        />
      );
    case 'country':
      return <CountryListEditor index={index} />;
    case 'customer_ids':
      return (
        <LookupListEditor
          index={index}
          entity="customers"
          placeholder={__('Search customers', 'yayloyalty')}
          max={CAMPAIGN_CONDITION_SCHEMA[type].max}
        />
      );
    case 'product_in_cart':
      return <LookupListEditor index={index} entity="products" placeholder={__('Search products', 'yayloyalty')} />;
    case 'category_in_cart':
      return <LookupListEditor index={index} entity="categories" placeholder={__('Search categories', 'yayloyalty')} />;
    case 'lifetime_spend':
      return <MoneyValueEditor index={index} operator={operator} />;
    default:
      return <IntValueEditor index={index} operator={operator} />;
  }
}

/* ─── list editors ───────────────────────────────────────────────────── */

/** A static vocabulary (roles, gateways, currencies) held as a string list. */
function OptionListEditor({
  index,
  options,
  placeholder,
}: {
  index: number;
  options: MultiSelectComboboxOption[];
  placeholder: string;
}) {
  const { control } = useFormContext<CampaignFormValues>();
  const { field } = useController({ control, name: `conditions.${index}.value` });
  const value = Array.isArray(field.value) ? (field.value as string[]) : [];

  return <OptionListSelect value={value} onChange={field.onChange} options={options} placeholder={placeholder} />;
}

/** Ids picked by name through the lookup route (customers, products, categories). */
function LookupListEditor({
  index,
  entity,
  placeholder,
  max,
}: {
  index: number;
  entity: LookupEntity;
  placeholder: string;
  max?: number;
}) {
  const { control } = useFormContext<CampaignFormValues>();
  const { field } = useController({ control, name: `conditions.${index}.value` });
  const ids = Array.isArray(field.value) ? (field.value as number[]) : [];

  return (
    <LookupMultiSelect entity={entity} value={ids} onChange={field.onChange} placeholder={placeholder} max={max} />
  );
}

/* ─── country_list ───────────────────────────────────────────────────── */

const COUNTRY_SOURCE_VALUES = ['shipping', 'billing', 'any'] as const;

const COUNTRY_SOURCE_LABELS: Record<(typeof COUNTRY_SOURCE_VALUES)[number], string> = {
  shipping: __('Shipping address', 'yayloyalty'),
  billing: __('Billing address', 'yayloyalty'),
  any: __('Either address', 'yayloyalty'),
};

function CountryListEditor({ index }: { index: number }) {
  const { control } = useFormContext<CampaignFormValues>();
  const { field } = useController({ control, name: `conditions.${index}.value` });
  const value = (field.value ?? {}) as { countries?: unknown; source?: unknown };
  const countries = Array.isArray(value.countries) ? (value.countries as string[]) : [];
  // No default source: the shipping address is absent on virtual orders, so the server refuses to guess one.
  const source = typeof value.source === 'string' ? value.source : '';

  return (
    <div className="flex flex-wrap items-center gap-2">
      <div className="min-w-56 flex-1">
        <OptionListSelect
          value={countries}
          onChange={(next) => field.onChange({ countries: next, source })}
          options={countryOptions()}
          placeholder={__('Choose countries', 'yayloyalty')}
        />
      </div>
      <Select value={source || undefined} onValueChange={(next) => field.onChange({ countries, source: next })}>
        <SelectTrigger size="sm" className="w-40">
          <SelectValue placeholder={__('Which address?', 'yayloyalty')} />
        </SelectTrigger>
        <SelectContent>
          {COUNTRY_SOURCE_VALUES.map((option) => (
            <SelectItem key={option} value={option}>
              {COUNTRY_SOURCE_LABELS[option]}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    </div>
  );
}

/* ─── int (lifetime_points / order_count) ───────────────────────────── */

function IntValueEditor({ index, operator }: { index: number; operator: string }) {
  const { control } = useFormContext<CampaignFormValues>();
  const { field } = useController({ control, name: `conditions.${index}.value` });

  if (operator === 'between') {
    const pair = Array.isArray(field.value) ? (field.value as number[]) : [0, 0];

    return (
      <div className="flex items-center gap-2">
        <NumberInputRoot
          size="small"
          className="w-24"
          min={0}
          value={pair[0]}
          onValueChange={(low) => field.onChange([low, pair[1] ?? low])}
        >
          <NumberInputInput />
        </NumberInputRoot>
        <span className="text-muted-foreground text-xs">{__('and', 'yayloyalty')}</span>
        <NumberInputRoot
          size="small"
          className="w-24"
          min={0}
          value={pair[1]}
          onValueChange={(high) => field.onChange([pair[0] ?? high, high])}
        >
          <NumberInputInput />
        </NumberInputRoot>
      </div>
    );
  }

  const scalar = typeof field.value === 'number' ? field.value : 0;

  return (
    <NumberInputRoot size="small" className="w-28" min={0} value={scalar} onValueChange={field.onChange}>
      <NumberInputInput />
    </NumberInputRoot>
  );
}

/* ─── money (lifetime_spend) ─────────────────────────────────────────── */

function MoneyValueEditor({ index, operator }: { index: number; operator: string }) {
  const { control } = useFormContext<CampaignFormValues>();
  const { field } = useController({ control, name: `conditions.${index}.value` });
  const value = (field.value ?? {}) as { amount?: unknown; currency?: unknown };
  const currency =
    typeof value.currency === 'string' && value.currency
      ? value.currency
      : window.yayLoyaltyMeta.wcMeta.currency_data.currency;

  if (operator === 'between') {
    const pair = Array.isArray(value.amount) ? (value.amount as number[]) : [0, 0];

    return (
      <div className="flex items-center gap-2">
        <NumberInputRoot
          size="small"
          className="w-28"
          min={0}
          decimalScale={2}
          value={pair[0]}
          onValueChange={(low) => field.onChange({ amount: [low, pair[1] ?? low], currency })}
        >
          <NumberInputInput />
          <NumberInputUnit unit={currency} />
        </NumberInputRoot>
        <span className="text-muted-foreground text-xs">{__('and', 'yayloyalty')}</span>
        <NumberInputRoot
          size="small"
          className="w-28"
          min={0}
          decimalScale={2}
          value={pair[1]}
          onValueChange={(high) => field.onChange({ amount: [pair[0] ?? high, high], currency })}
        >
          <NumberInputInput />
          <NumberInputUnit unit={currency} />
        </NumberInputRoot>
      </div>
    );
  }

  const scalar = typeof value.amount === 'number' ? value.amount : 0;

  return (
    <NumberInputRoot
      size="small"
      className="w-28"
      min={0}
      decimalScale={2}
      value={scalar}
      onValueChange={(next) => field.onChange({ amount: next, currency })}
    >
      <NumberInputInput />
      <NumberInputUnit unit={currency} />
    </NumberInputRoot>
  );
}

/* ─── shared helpers: defaults, reshaping, minimal validation ───────── */

/** The default operator on add. */
function firstOperatorFor(type: CampaignConditionKind): string {
  return CAMPAIGN_CONDITION_SCHEMA[type].operators[0];
}

/** Starting value on add: empty for lists so validation flags an unfilled condition; 0 in store currency for numeric kinds. */
function defaultConditionValue(type: CampaignConditionKind): unknown {
  switch (CAMPAIGN_CONDITION_SCHEMA[type].value) {
    case 'string_list':
    case 'int_list':
    case 'currency_list':
      return [];
    case 'country_list':
      return { countries: [], source: '' };
    case 'int':
      return 0;
    case 'money':
      return { amount: 0, currency: window.yayLoyaltyMeta.wcMeta.currency_data.currency };
    default:
      return null;
  }
}

/**
 * Switching to or from `between` changes a numeric value's shape (scalar <->
 * [low, high]); called before the operator is committed so form state never
 * holds a half-shaped value. Other kinds pass through untouched.
 */
function reshapeConditionValue(
  valueKind: string,
  currentValue: unknown,
  previousOperator: string,
  nextOperator: string,
): unknown {
  const wasBetween = previousOperator === 'between';
  const willBeBetween = nextOperator === 'between';

  if (wasBetween === willBeBetween) {
    return currentValue;
  }

  if (valueKind === 'int') {
    if (willBeBetween) {
      const scalar = typeof currentValue === 'number' ? currentValue : 0;
      return [scalar, scalar];
    }

    const pair = Array.isArray(currentValue) ? (currentValue as number[]) : [0, 0];
    return typeof pair[0] === 'number' ? pair[0] : 0;
  }

  if (valueKind === 'money') {
    const object = (currentValue ?? {}) as { amount?: unknown; currency?: unknown };
    const currency =
      typeof object.currency === 'string' && object.currency
        ? object.currency
        : window.yayLoyaltyMeta.wcMeta.currency_data.currency;

    if (willBeBetween) {
      const scalar = typeof object.amount === 'number' ? object.amount : 0;
      return { amount: [scalar, scalar], currency };
    }

    const pair = Array.isArray(object.amount) ? (object.amount as number[]) : [0, 0];
    return { amount: typeof pair[0] === 'number' ? pair[0] : 0, currency };
  }

  return currentValue;
}

/**
 * Minimal client-side checks (non-empty lists, the customer_ids cap,
 * `between` ordering, country source, spend currency), never the rest of
 * CampaignConditionSet::validate(). Returns a message, or null when the
 * server would accept the value.
 */
export function validateConditionValue(condition: CampaignCondition | undefined): string | null {
  if (!condition) {
    return null;
  }

  const spec = CAMPAIGN_CONDITION_SCHEMA[condition.type];

  switch (spec.value) {
    case 'string_list':
    case 'currency_list': {
      const list = Array.isArray(condition.value) ? condition.value : [];
      return list.length === 0 ? __('Add at least one value.', 'yayloyalty') : null;
    }

    case 'int_list': {
      const list = Array.isArray(condition.value) ? condition.value : [];

      if (list.length === 0) {
        return __('Add at least one id.', 'yayloyalty');
      }

      if (spec.max && list.length > spec.max) {
        return sprintf(
          /* translators: %d: the max number of ids this condition accepts. */
          __('At most %d ids — use a customer role condition for a larger segment.', 'yayloyalty'),
          spec.max,
        );
      }

      return null;
    }

    case 'country_list': {
      const value = (condition.value ?? {}) as { countries?: unknown; source?: unknown };
      const countries = Array.isArray(value.countries) ? value.countries : [];

      if (countries.length === 0) {
        return __('Add at least one country.', 'yayloyalty');
      }

      if (!value.source) {
        return __('Choose which address to check.', 'yayloyalty');
      }

      return null;
    }

    case 'int': {
      if (condition.operator === 'between') {
        return validateBetweenPair(condition.value);
      }

      return typeof condition.value === 'number' ? null : __('Enter a value.', 'yayloyalty');
    }

    case 'money': {
      const value = (condition.value ?? {}) as { amount?: unknown; currency?: unknown };

      if (!value.currency) {
        return __('Choose a currency.', 'yayloyalty');
      }

      if (condition.operator === 'between') {
        return validateBetweenPair(value.amount);
      }

      return typeof value.amount === 'number' ? null : __('Enter an amount.', 'yayloyalty');
    }

    default:
      return null;
  }
}

function validateBetweenPair(value: unknown): string | null {
  const pair = Array.isArray(value) ? value : [];
  const [low, high] = pair;

  if (typeof low !== 'number' || typeof high !== 'number') {
    return __('Enter both the low and high bounds.', 'yayloyalty');
  }

  if (low > high) {
    return __('The lower bound must not be greater than the upper bound.', 'yayloyalty');
  }

  return null;
}
