import type { FieldValues, ResolverResult } from 'react-hook-form';
import { __, sprintf } from '@wordpress/i18n';

import { storeCurrency } from '@/lib/helpers/format.helper';
import { CAMPAIGN_CONDITION_SCHEMA, CampaignCondition, CampaignConditionType } from '@/lib/schema/campaign.schema';
import type { ConditionTree } from '@/lib/schema/condition-groups.schema';

/**
 * The slice of an editor form the condition editor reads and writes. The campaign and reward forms
 * both hold a ConditionGroups tree under `conditions`; the reward types are a subset of the campaign
 * ones with the same value shapes, so one spec table (CAMPAIGN_CONDITION_SCHEMA) serves both.
 */
export interface ConditionsFormValues {
  conditions: ConditionTree<CampaignCondition>;
}

export type ConditionPath = `conditions.groups.${number}.conditions.${number}`;

export function conditionPath(groupIndex: number, index: number): ConditionPath {
  return `conditions.groups.${groupIndex}.conditions.${index}`;
}

/** The default operator on add. */
export function firstOperatorFor(type: CampaignConditionType): string {
  return CAMPAIGN_CONDITION_SCHEMA[type].operators[0];
}

/** Starting value on add: empty for lists so validation flags an unfilled condition; 0 in store currency for numeric kinds. */
export function defaultConditionValue(type: CampaignConditionType): unknown {
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
      return { amount: 0, currency: storeCurrency() };
    default:
      return null;
  }
}

/**
 * Switching to or from `between` changes a numeric value's shape (scalar <->
 * [low, high]); called before the operator is committed so form state never
 * holds a half-shaped value. Other kinds pass through untouched.
 */
export function reshapeConditionValue(
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
    const currency = typeof object.currency === 'string' && object.currency ? object.currency : storeCurrency();

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

  if (!spec) {
    return null;
  }

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

type RowErrors = Array<Record<string, unknown> | undefined>;
type GroupErrors = Array<(Record<string, unknown> & { conditions?: RowErrors }) | undefined>;

/**
 * Adds per-condition value errors to the zodResolver result, at
 * `conditions.groups.<g>.conditions.<i>.value`. `value` is z.unknown(), so zod
 * never checks it; running validateConditionValue in the resolver keeps it on
 * RHF's normal lifecycle for every save path, unlike setError (wiped by the
 * next resolver run) or a native submit listener (never fired by a dialog
 * button). Builds the errors through plain records because RHF's mapped error
 * type for nested arrays of unknown does not iterate.
 */
export function mergeConditionErrors<V extends FieldValues>(result: ResolverResult<V>, values: V): ResolverResult<V> {
  const tree = (values as Partial<ConditionsFormValues>).conditions;
  const groups = Array.isArray(tree?.groups) ? tree.groups : [];
  const additions: Array<{ groupIndex: number; index: number; message: string }> = [];

  groups.forEach((group, groupIndex) => {
    (Array.isArray(group?.conditions) ? group.conditions : []).forEach((condition, index) => {
      const message = validateConditionValue(condition);

      if (message) {
        additions.push({ groupIndex, index, message });
      }
    });
  });

  if (additions.length === 0) {
    return result;
  }

  const currentErrors = result.errors as Record<string, unknown> & {
    conditions?: Record<string, unknown> & { groups?: GroupErrors };
  };
  const groupErrors: GroupErrors = Array.isArray(currentErrors.conditions?.groups)
    ? [...currentErrors.conditions.groups]
    : [];

  additions.forEach(({ groupIndex, index, message }) => {
    const group = { ...groupErrors[groupIndex] };
    const rows: RowErrors = Array.isArray(group.conditions) ? [...group.conditions] : [];

    rows[index] = { ...rows[index], value: { type: 'manual', message } };
    group.conditions = rows;
    groupErrors[groupIndex] = group;
  });

  return {
    values: {},
    errors: { ...currentErrors, conditions: { ...currentErrors.conditions, groups: groupErrors } },
  } as unknown as ResolverResult<V>;
}
