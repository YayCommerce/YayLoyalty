import { describe, expect, it } from 'vitest';

import type { ActivityEntry } from '@/lib/schema/activity.schema';
import { toActivityRow } from '@/pages/customers/drawer/customer-drawer.activity-rows';

/**
 * `toActivityRow()` composes a row's title/sub-line from `event_type` +
 * `data` — pure, no React. Covers the new real-path cases this task adds
 * (`account_deleted`, points-only `account_created`) plus the defensive
 * `str()`/`num()` fallbacks for a row with missing `data` keys.
 */

function entry(overrides: Partial<ActivityEntry> = {}): ActivityEntry {
  return {
    id: 1,
    occurred_at: '2026-09-01 12:00:00',
    event_type: 'account_created',
    data: {},
    actor_kind: 'automatic',
    actor_name: null,
    staff_only: false,
    ...overrides,
  };
}

describe('toActivityRow', () => {
  it('renders account_deleted with email and the frozen balance', () => {
    const row = toActivityRow(
      entry({
        event_type: 'account_deleted',
        data: { email: 'member@example.com', balance_at_deletion: 1200 },
      }),
    );

    expect(row.title).toBe('Account deleted');
    expect(row.subLine).toBe('member@example.com · 1,200 points frozen');
  });

  it('renders the points-only account_created sub-line', () => {
    const row = toActivityRow(entry({ event_type: 'account_created', data: { points: 50, source: 'order' } }));

    expect(row.title).toBe('Account created');
    expect(row.subLine).toBe('50 points');
  });

  it('falls back to empty/zero for missing data keys without throwing', () => {
    const row = toActivityRow(entry({ event_type: 'account_deleted', data: {} }));

    expect(row.subLine).toBe(' · 0 points frozen');
  });

  it('falls back for a points_adjusted row missing its note', () => {
    const row = toActivityRow(entry({ event_type: 'points_adjusted', data: { points: -50 } }));

    expect(row.subLine).toBe('-50 · ""');
  });
});
