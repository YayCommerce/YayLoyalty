import { describe, expect, it } from 'vitest';

import * as PointsMath from '@/lib/points-math/points-math';
import vectors from '../../../tests/fixtures/points-math-vectors.json';

/**
 * The cross-language contract. The same fixture runs under PHPUnit against
 * `YayLoyalty\Utils\PointsMath`; keys are the PHP method names, exports here
 * are their camelCase twins. A vector added on one side must pass on the
 * other, and an export added without vectors fails here — so the two
 * implementations cannot drift silently. PHP is the source of truth.
 */
type Case = { inputs: unknown[]; expected: number | null };
type Vectors = Record<string, Case[]>;

const toCamel = (name: string) => name.replace(/_([a-z])/g, (_, c: string) => c.toUpperCase());
const toSnake = (name: string) => name.replace(/[A-Z]/g, (c) => `_${c.toLowerCase()}`);

const fixture = vectors as Vectors;
const exported = Object.entries(PointsMath)
  .filter(([, value]) => typeof value === 'function')
  .map(([name]) => name);

describe('points-math vectors', () => {
  it('every vector key has an implementation', () => {
    for (const key of Object.keys(fixture)) {
      expect(PointsMath, `${key} has vectors but no export ${toCamel(key)}`).toHaveProperty(toCamel(key));
    }
  });

  it('every exported function has vectors', () => {
    expect(exported.map(toSnake).sort()).toEqual(Object.keys(fixture).sort());
  });

  describe.each(Object.entries(fixture))('%s', (key, cases) => {
    const fn = (PointsMath as unknown as Record<string, (...args: unknown[]) => unknown>)[toCamel(key)];

    it.each(cases)('%j', ({ inputs, expected }) => {
      // toBe is Object.is: a `-0` leaking out of Math.round/Math.ceil fails here on purpose.
      expect(fn(...inputs)).toBe(expected);
    });
  });
});
