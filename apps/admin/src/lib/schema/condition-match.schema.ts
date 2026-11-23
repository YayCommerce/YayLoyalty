import { z } from 'zod';

/**
 * Mirrors YayLoyalty\Utils\ConditionMatch, the source of truth. A campaign's or a reward's
 * `conditions`: one list joined by `all` (AND) or `any` (OR).
 */

export const CONDITION_MATCHES = ['all', 'any'] as const;

export type ConditionMatch = (typeof CONDITION_MATCHES)[number];

export const conditionMatchSchema = z.enum(CONDITION_MATCHES);

export interface ConditionList<T> {
  match: ConditionMatch;
  conditions: T[];
}

/** The write shape for a list of `leaf` conditions. */
export function conditionListSchema<T extends z.ZodTypeAny>(leaf: T) {
  return z.object({
    match: conditionMatchSchema,
    conditions: z.array(leaf),
  });
}

export function emptyConditionList<T>(): ConditionList<T> {
  return { match: 'all', conditions: [] };
}

/**
 * Mirrors ConditionMatch::parse(), the source of truth. Either wire shape to { match, conditions }: a flat
 * list (1.0.0, or a payload cached before the upgrade) is `all`. Defensive, as the wire type is a loose
 * union: an entry that is not a { type } object is dropped, so a malformed row shows fewer rules rather
 * than crashing.
 */
export function toConditionList<T extends { type: string }>(conditions: unknown): ConditionList<T> {
  if (Array.isArray(conditions)) {
    return { match: 'all', conditions: conditions.filter(isCondition) as T[] };
  }

  if (!isRecord(conditions)) {
    return emptyConditionList();
  }

  return {
    match: conditions.match === 'any' ? 'any' : 'all',
    conditions: (Array.isArray(conditions.conditions) ? conditions.conditions.filter(isCondition) : []) as T[],
  };
}

/** Whether there is a condition. Without one, everyone qualifies. */
export function hasConditions(conditions: unknown): boolean {
  return toConditionList(conditions).conditions.length > 0;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return !!value && typeof value === 'object' && !Array.isArray(value);
}

function isCondition(entry: unknown): entry is { type: string } {
  return isRecord(entry) && typeof entry.type === 'string';
}
