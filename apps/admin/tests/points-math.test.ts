import { describe, expect, it } from 'vitest';

import { ROUNDING, roundPoints } from '@/lib/points-math/points-math';
import { pointsForSpend } from '@/components/setup-wizard/setup-wizard.form';

// The same cases as tests/unit/Utils/PointsMathTest.php: PHP and the mirror must agree.
const CASES: Array<[string, number, number, number, number]> = [
  ['just below a whole number', 0.57 * 100, 57, 57, 57],
  ['just above a whole number', 0.07 * 100, 7, 7, 7],
  ['spend 3 to receive 1', (1 / 3) * 3, 1, 1, 1],
  ['a half', 2.5, 2, 3, 3],
  ['a fraction', 0.3 * 3, 0, 1, 1],
  ['zero', 0, 0, 0, 0],
];

describe('roundPoints', () => {
  it.each(CASES)('%s', (_, raw, down, nearest, up) => {
    expect(roundPoints(raw, ROUNDING.DOWN)).toBe(down);
    expect(roundPoints(raw, ROUNDING.NEAREST)).toBe(nearest);
    expect(roundPoints(raw, ROUNDING.UP)).toBe(up);
  });
});

describe('pointsForSpend', () => {
  it('rounds down like the purchase campaign, without losing a point to float error', () => {
    expect(pointsForSpend({ earnPoints: 100, earnSpend: 1 }, 0.57)).toBe(57);
    expect(pointsForSpend({ earnPoints: 1, earnSpend: 3 }, 10)).toBe(3);
  });

  it('earns nothing without a spend amount', () => {
    expect(pointsForSpend({ earnPoints: 100, earnSpend: 0 }, 50)).toBe(0);
  });
});
