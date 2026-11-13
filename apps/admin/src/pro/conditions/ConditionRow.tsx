import { useEffect } from 'react';
import { XIcon } from 'lucide-react';
import { get, FieldError as HookFieldError, useController, useFormContext, useWatch } from 'react-hook-form';
import { __ } from '@wordpress/i18n';

import { storeCurrency } from '@/lib/helpers/format.helper';
import { countryOptions, currencyOptions, paymentGatewayOptions, roleOptions } from '@/lib/helpers/meta-options.helper';
import { CAMPAIGN_CONDITION_SCHEMA, CampaignConditionType, COUNTRY_SOURCE_VALUES } from '@/lib/schema/campaign.schema';
import { LookupEntity } from '@/lib/schema/lookup.schema';
import { Button } from '@/components/ui/button';
import { AMOUNT_INPUT_WIDTH } from '@/components/ui/custom/amount-inputs';
import { LookupMultiSelect } from '@/components/ui/custom/lookup-multi-select';
import { MultiSelectComboboxOption } from '@/components/ui/custom/multi-select-combobox';
import { OptionListSelect } from '@/components/ui/custom/option-list-select';
import { FieldError } from '@/components/ui/field';
import { NumberInputInput, NumberInputRoot, NumberInputUnit } from '@/components/ui/number-input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { conditionTypeLabel } from '../campaigns/campaign-labels';
import { ConditionPath, ConditionsFormValues, reshapeConditionValue, validateConditionValue } from './condition-form';

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
export function ConditionRow({
  path,
  type,
  onRemove,
}: {
  path: ConditionPath;
  type: CampaignConditionType;
  onRemove: () => void;
}) {
  const { control, setValue, getValues, formState, clearErrors } = useFormContext<ConditionsFormValues>();
  const spec = CAMPAIGN_CONDITION_SCHEMA[type];
  const operator = useWatch({ control, name: `${path}.operator` });
  const condition = useWatch({ control, name: path });
  const error = get(formState.errors, `${path}.value`) as HookFieldError | undefined;

  // A resolver error would otherwise stay until the next submit; clear it as soon as the row is valid.
  useEffect(() => {
    if (error && !validateConditionValue(condition)) {
      clearErrors(`${path}.value`);
    }
  }, [condition, error, path, clearErrors]);

  function handleOperatorChange(nextOperator: string) {
    const currentValue = getValues(`${path}.value`);
    const reshaped = reshapeConditionValue(spec.value, currentValue, operator, nextOperator);

    setValue(`${path}.operator`, nextOperator, { shouldDirty: true });
    setValue(`${path}.value`, reshaped, { shouldDirty: true });
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
          <ConditionValueEditor path={path} type={type} operator={operator} />
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
  path,
  type,
  operator,
}: {
  path: ConditionPath;
  type: CampaignConditionType;
  operator: string;
}) {
  switch (type) {
    case 'user_role':
      return <OptionListEditor path={path} options={roleOptions()} placeholder={__('Choose roles', 'yayloyalty')} />;
    case 'payment_method':
      return (
        <OptionListEditor
          path={path}
          options={paymentGatewayOptions()}
          placeholder={__('Choose payment methods', 'yayloyalty')}
        />
      );
    case 'currency':
      return (
        <OptionListEditor path={path} options={currencyOptions()} placeholder={__('Choose currencies', 'yayloyalty')} />
      );
    case 'country':
      return <CountryListEditor path={path} />;
    case 'customer_ids':
      return (
        <LookupListEditor
          path={path}
          entity="customers"
          placeholder={__('Search customers', 'yayloyalty')}
          max={CAMPAIGN_CONDITION_SCHEMA[type].max}
        />
      );
    case 'product_in_cart':
      return <LookupListEditor path={path} entity="products" placeholder={__('Search products', 'yayloyalty')} />;
    case 'category_in_cart':
      return <LookupListEditor path={path} entity="categories" placeholder={__('Search categories', 'yayloyalty')} />;
    case 'lifetime_spend':
      return <MoneyValueEditor path={path} operator={operator} />;
    default:
      return <IntValueEditor path={path} operator={operator} />;
  }
}

// List editors

/** A static vocabulary (roles, gateways, currencies) held as a string list. */
function OptionListEditor({
  path,
  options,
  placeholder,
}: {
  path: ConditionPath;
  options: MultiSelectComboboxOption[];
  placeholder: string;
}) {
  const { control } = useFormContext<ConditionsFormValues>();
  const { field } = useController({ control, name: `${path}.value` });
  const value = Array.isArray(field.value) ? (field.value as string[]) : [];

  return <OptionListSelect value={value} onChange={field.onChange} options={options} placeholder={placeholder} />;
}

/** Ids picked by name through the lookup route (customers, products, categories). */
function LookupListEditor({
  path,
  entity,
  placeholder,
  max,
}: {
  path: ConditionPath;
  entity: LookupEntity;
  placeholder: string;
  max?: number;
}) {
  const { control } = useFormContext<ConditionsFormValues>();
  const { field } = useController({ control, name: `${path}.value` });
  const ids = Array.isArray(field.value) ? (field.value as number[]) : [];

  return (
    <LookupMultiSelect entity={entity} value={ids} onChange={field.onChange} placeholder={placeholder} max={max} />
  );
}

// country_list

const COUNTRY_SOURCE_LABELS: Record<(typeof COUNTRY_SOURCE_VALUES)[number], string> = {
  shipping: __('Shipping address', 'yayloyalty'),
  billing: __('Billing address', 'yayloyalty'),
  any: __('Either address', 'yayloyalty'),
};

function CountryListEditor({ path }: { path: ConditionPath }) {
  const { control } = useFormContext<ConditionsFormValues>();
  const { field } = useController({ control, name: `${path}.value` });
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

// int (lifetime_points, order_count)

function IntValueEditor({ path, operator }: { path: ConditionPath; operator: string }) {
  const { control } = useFormContext<ConditionsFormValues>();
  const { field } = useController({ control, name: `${path}.value` });

  if (operator === 'between') {
    const pair = Array.isArray(field.value) ? (field.value as number[]) : [0, 0];

    return (
      <div className="flex items-center gap-2">
        <NumberInputRoot
          size="small"
          className={AMOUNT_INPUT_WIDTH}
          min={0}
          value={pair[0]}
          onValueChange={(low) => field.onChange([low, pair[1] ?? low])}
        >
          <NumberInputInput />
        </NumberInputRoot>
        <span className="text-muted-foreground text-xs">{__('and', 'yayloyalty')}</span>
        <NumberInputRoot
          size="small"
          className={AMOUNT_INPUT_WIDTH}
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
    <NumberInputRoot size="small" className={AMOUNT_INPUT_WIDTH} min={0} value={scalar} onValueChange={field.onChange}>
      <NumberInputInput />
    </NumberInputRoot>
  );
}

// money (lifetime_spend)

function MoneyValueEditor({ path, operator }: { path: ConditionPath; operator: string }) {
  const { control } = useFormContext<ConditionsFormValues>();
  const { field } = useController({ control, name: `${path}.value` });
  const value = (field.value ?? {}) as { amount?: unknown; currency?: unknown };
  const currency = typeof value.currency === 'string' && value.currency ? value.currency : storeCurrency();

  if (operator === 'between') {
    const pair = Array.isArray(value.amount) ? (value.amount as number[]) : [0, 0];

    return (
      <div className="flex items-center gap-2">
        <NumberInputRoot
          size="small"
          className={AMOUNT_INPUT_WIDTH}
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
          className={AMOUNT_INPUT_WIDTH}
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
      className={AMOUNT_INPUT_WIDTH}
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
