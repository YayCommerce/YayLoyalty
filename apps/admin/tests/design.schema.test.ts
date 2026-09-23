import { describe, expect, it } from 'vitest';

import { designSchema } from '@/lib/schema/design.schema';
import fixture from './fixtures/design-defaults.json';

/**
 * Round-trip validation: designSchema parses the PHP default payload,
 * rejects invalid enum values, and accepts edge cases like empty badge arrays.
 */
describe('designSchema', () => {
  it('parses the PHP default design', () => {
    const result = designSchema.parse(fixture);
    expect(result).toBeDefined();
    expect(result.launcher.position).toBe('bottom_right');
    expect(result.tier.badges).toHaveLength(3);
  });

  it('rejects unknown enum value', () => {
    const invalid = {
      ...fixture,
      launcher: {
        ...fixture.launcher,
        position: 'middle' as never, // Not in the allowed list
      },
    };

    expect(() => designSchema.parse(invalid)).toThrow();
  });

  it('accepts empty tier.badges array', () => {
    const withEmptyBadges = {
      ...fixture,
      tier: {
        badges: [],
      },
    };

    const result = designSchema.parse(withEmptyBadges);
    expect(result.tier.badges).toEqual([]);
  });
});
