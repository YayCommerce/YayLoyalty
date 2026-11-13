import { z } from 'zod';

/**
 * Mirrors YayLoyalty\Utils\ConditionGroups, the source of truth. A campaign's or a reward's
 * `conditions`: groups of conditions, each group and the root joined by `all` (AND) or `any` (OR).
 */

export const CONDITION_MATCHES = ['all', 'any'] as const;

export type ConditionMatch = (typeof CONDITION_MATCHES)[number];

/** Mirrors ConditionGroups::MAX_GROUPS, the source of truth. */
export const MAX_CONDITION_GROUPS = 10;

export const conditionMatchSchema = z.enum(CONDITION_MATCHES);

export interface ConditionGroup<T> {
  match: ConditionMatch;
  conditions: T[];
}

export interface ConditionTree<T> {
  match: ConditionMatch;
  groups: ConditionGroup<T>[];
}

/** The write shape for a tree of `leaf` conditions. */
export function conditionTreeSchema<T extends z.ZodTypeAny>(leaf: T) {
  return z.object({
    match: conditionMatchSchema,
    groups: z
      .array(
        z.object({
          match: conditionMatchSchema,
          conditions: z.array(leaf),
        }),
      )
      .max(MAX_CONDITION_GROUPS),
  });
}

export function emptyConditionTree<T>(): ConditionTree<T> {
  return { match: 'all', groups: [] };
}

/**
 * Mirrors ConditionGroups::parse(), the source of truth. Either wire shape to the tree: a flat list
 * (1.0.0, or a payload cached before the upgrade) is one `all` group. Defensive, as the wire type is a
 * loose union: an entry that is not a { type } object is dropped, so a malformed row shows fewer rules
 * rather than crashing.
 */
export function toConditionTree<T extends { type: string }>(conditions: unknown): ConditionTree<T> {
  if (Array.isArray(conditions)) {
    const list = conditions.filter(isCondition) as T[];

    return { match: 'all', groups: list.length > 0 ? [{ match: 'all', conditions: list }] : [] };
  }

  if (!isRecord(conditions) || !Array.isArray(conditions.groups)) {
    return emptyConditionTree();
  }

  return {
    match: matchOf(conditions),
    groups: conditions.groups.filter(isRecord).map((group) => ({
      match: matchOf(group),
      conditions: (Array.isArray(group.conditions) ? group.conditions.filter(isCondition) : []) as T[],
    })),
  };
}

/** Whether any group holds a condition. Without one, everyone qualifies. */
export function hasConditions(conditions: unknown): boolean {
  return toConditionTree(conditions).groups.some((group) => group.conditions.length > 0);
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return !!value && typeof value === 'object' && !Array.isArray(value);
}

function isCondition(entry: unknown): entry is { type: string } {
  return isRecord(entry) && typeof entry.type === 'string';
}

function matchOf(node: Record<string, unknown>): ConditionMatch {
  return node.match === 'any' ? 'any' : 'all';
}
