import { useEffect } from 'react';
import { PlusIcon, XIcon } from 'lucide-react';
import { useController, useFieldArray, useFormContext, useWatch } from 'react-hook-form';
import { __, sprintf } from '@wordpress/i18n';

import {
  CAMPAIGN_CONDITION_SCHEMA,
  CAMPAIGN_CONDITION_TYPES,
  CampaignCondition,
  CampaignConditionKind,
} from '@/lib/schema/campaign.schema';
import { Button } from '@/components/ui/button';
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
import { TagsInput } from '@/components/ui/tags-input';
import { conditionTypeLabel } from '../campaign-labels';
import { CampaignFormValues } from './campaign-editor.form';

/**
 * Section 4, Conditions. `useFieldArray` over the form's `conditions` —
 * a flat, ANDed list with at most one entry per type (`CampaignConditionSet`,
 * PHP). The offerable types come from `CAMPAIGN_CONDITION_TYPES`
 * (campaign.schema.ts) — never the wireframe's list, which offers `tier`
 * (gated out server-side) and omits five real types.
 *
 * `conditions[].value` is `z.unknown()` on the shared schema (campaign.schema.ts)
 * — its shape genuinely varies per type — so per-type validation
 * (`validateConditionValue`, below) runs inside `CampaignEditor.tsx`'s custom
 * resolver (`mergeConditionErrors`, campaign-editor.form.ts) rather than here.
 * That keeps it on RHF's normal resolver lifecycle so it fires for every save
 * path — the header's Save button, "Save as draft", and `UnsavedChangeDialog`'s
 * Save — instead of only a native `<form>` `submit` event.
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

/** Merchant-facing labels for the operators `CAMPAIGN_CONDITION_SCHEMA` recognises, across every type. */
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

/**
 * One row in `CampaignEditorConditions`' list above: the type (fixed once
 * added — `CampaignConditionSet` has no "change a condition's type" concept,
 * only add/remove), an operator `Select` scoped to that type's own operators,
 * the value editor for its kind, and a remove button.
 */
function ConditionRow({ index, type, onRemove }: { index: number; type: CampaignConditionKind; onRemove: () => void }) {
  const { control, setValue, getValues, formState, clearErrors } = useFormContext<CampaignFormValues>();
  const spec = CAMPAIGN_CONDITION_SCHEMA[type];
  const operator = useWatch({ control, name: `conditions.${index}.operator` });
  const condition = useWatch({ control, name: `conditions.${index}` });
  const error = formState.errors.conditions?.[index]?.value;

  // Errors here are raised by `CampaignEditor.tsx`'s custom resolver (`mergeConditionErrors`,
  // campaign-editor.form.ts) — `value`'s shape varies per type, so the shared zodResolver alone
  // never validates it. Clear the moment the row becomes valid again, rather than waiting for
  // the next submit.
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
 * Dispatch on the value KIND `CAMPAIGN_CONDITION_SCHEMA` (campaign.schema.ts,
 * phase-01) declares for a condition type — the table in phase-05's plan.
 * `type` decides which of the six editors below renders; `operator` only
 * matters to the two numeric kinds, where `between` reshapes the value from
 * a scalar to a `[low, high]` pair (see `reshapeConditionValue()`).
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
  const spec = CAMPAIGN_CONDITION_SCHEMA[type];

  switch (spec.value) {
    case 'string_list':
      return <StringListEditor index={index} />;
    case 'int_list':
      return <IntListEditor index={index} max={spec.max} />;
    case 'currency_list':
      return <StringListEditor index={index} uppercase placeholder={__('e.g. USD', 'yayloyalty')} />;
    case 'country_list':
      return <CountryListEditor index={index} />;
    case 'int':
      return <IntValueEditor index={index} operator={operator} />;
    case 'money':
      return <MoneyValueEditor index={index} operator={operator} />;
    default:
      return null;
  }
}

/* ─── list editors (string_list / currency_list) ────────────────────── */

function StringListEditor({
  index,
  uppercase = false,
  placeholder = __('Type a value and press Enter', 'yayloyalty'),
}: {
  index: number;
  uppercase?: boolean;
  placeholder?: string;
}) {
  const { control } = useFormContext<CampaignFormValues>();
  const { field } = useController({ control, name: `conditions.${index}.value` });
  const value = Array.isArray(field.value) ? (field.value as string[]) : [];

  return (
    <TagsInput
      value={value}
      onValueChange={(next) => field.onChange(uppercase ? next.map((item) => item.toUpperCase()) : next)}
      placeholder={placeholder}
    />
  );
}

/* ─── int_list (customer_ids / product_in_cart / category_in_cart) ─── */

function IntListEditor({ index, max }: { index: number; max?: number }) {
  const { control } = useFormContext<CampaignFormValues>();
  const { field } = useController({ control, name: `conditions.${index}.value` });
  const numbers = Array.isArray(field.value) ? (field.value as number[]) : [];

  function handleChange(next: string[]) {
    const parsed = next
      .map((raw) => Number.parseInt(raw, 10))
      .filter((parsedId) => Number.isInteger(parsedId) && parsedId > 0);
    const deduped = Array.from(new Set(parsed));

    field.onChange(max ? deduped.slice(0, max) : deduped);
  }

  return (
    <div className="flex flex-col gap-1">
      <TagsInput
        value={numbers.map(String)}
        onValueChange={handleChange}
        placeholder={__('Enter an id and press Enter', 'yayloyalty')}
      />
      {max && (
        <span className="text-muted-foreground text-[11px]">
          {sprintf(
            /* translators: %d: the max number of ids this condition accepts. */
            __('Up to %d ids — for a larger segment, use a customer role condition instead.', 'yayloyalty'),
            max,
          )}
        </span>
      )}
    </div>
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
  // No default source, on purpose: `CampaignConditionSet::validate()` refuses to guess one
  // because the shipping address is absent on virtual/downloadable orders — the same reason
  // `validateConditionValue()` below treats a missing source as an error rather than a default.
  const source = typeof value.source === 'string' ? value.source : '';

  return (
    <div className="flex flex-wrap items-center gap-2">
      <TagsInput
        className="min-w-56 flex-1"
        value={countries}
        onValueChange={(next) => field.onChange({ countries: next.map((code) => code.toUpperCase()), source })}
        placeholder={__('Country code, e.g. US', 'yayloyalty')}
      />
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

/** The first operator `CAMPAIGN_CONDITION_SCHEMA` lists for a type — the sensible default on add. */
function firstOperatorFor(type: CampaignConditionKind): string {
  return CAMPAIGN_CONDITION_SCHEMA[type].operators[0];
}

/**
 * A freshly-added condition's starting value — intentionally EMPTY for list
 * shapes (so `validateConditionValue()` flags it immediately, matching
 * `ConditionValueRules::assert_non_empty_list()`'s server-side rejection of
 * an unfilled condition) and a plain `0`/store-currency pair for numeric
 * shapes, which are valid on their own.
 */
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
 * Switching a numeric condition's operator to/from `between` changes its
 * value's SHAPE (scalar <-> `[low, high]` pair) — called from `ConditionRow`'s
 * operator `Select` above before the new operator is committed, so form state
 * is never left holding a half-shaped value (a pair under a non-`between`
 * operator, or a scalar under `between`).
 * Non-numeric kinds ignore operator changes entirely: `in`/`not_in` never
 * affects a list's shape.
 */
function reshapeConditionValue(
  /** `CAMPAIGN_CONDITION_SCHEMA[type].value` — only `'int'`/`'money'` ever reshape; every other kind passes through untouched. */
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
 * Minimal client-side validation — the five checks phase-05's plan permits
 * (non-empty lists, `customer_ids` <= 100, `between` ordering, `country`'s
 * `source`, `lifetime_spend`'s `currency`), never the rest of
 * `ConditionValueRules`/`CampaignConditionSet::validate()`. Returns a
 * merchant-facing message, or `null` when the value would pass the server.
 *
 * Exported because `campaign-editor.form.ts`'s `mergeConditionErrors()` reuses
 * it inside `CampaignEditor.tsx`'s resolver — the only export from this file
 * besides the section component itself.
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
