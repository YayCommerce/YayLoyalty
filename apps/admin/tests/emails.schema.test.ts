import { describe, expect, it } from 'vitest';

import { emailsFormSchema, toEmailsFormValues, type EmailsResponse } from '@/lib/schema/emails.schema';

describe('emailsFormSchema', () => {
  it('accepts a form covering Free and Pro-only ids (bare string keys)', () => {
    const result = emailsFormSchema.parse({
      emails: {
        points_earned: true,
        points_expiring_soon: false,
        points_expired: true,
        reward_claimed: true, // Pro-only id — must be accepted, not a closed enum.
      },
      throttlePerWeek: 3,
    });

    expect(result.emails.points_earned).toBe(true);
    expect(result.emails.reward_claimed).toBe(true);
  });

  it('accepts any subset of ids (record allows partial)', () => {
    const result = emailsFormSchema.parse({ emails: { points_earned: true }, throttlePerWeek: 3 });

    expect(result.emails).toEqual({ points_earned: true });
  });

  it('rejects non-boolean values', () => {
    expect(() => emailsFormSchema.parse({ emails: { points_earned: 'true' }, throttlePerWeek: 3 })).toThrow();
  });

  it('rejects a payload without an emails object', () => {
    expect(() => emailsFormSchema.parse({ throttlePerWeek: 3 })).toThrow();
  });

  it('rejects a payload without throttlePerWeek', () => {
    expect(() => emailsFormSchema.parse({ emails: {} })).toThrow();
  });

  it('rejects a negative throttlePerWeek', () => {
    expect(() => emailsFormSchema.parse({ emails: {}, throttlePerWeek: -1 })).toThrow();
  });

  it('rejects a non-integer throttlePerWeek', () => {
    expect(() => emailsFormSchema.parse({ emails: {}, throttlePerWeek: 1.5 })).toThrow();
  });

  it('accepts a zero throttlePerWeek', () => {
    const result = emailsFormSchema.parse({ emails: {}, throttlePerWeek: 0 });

    expect(result.throttlePerWeek).toBe(0);
  });
});

/** Flattens every group's items into an id => enabled map and carries the throttle cap through unchanged. */
describe('toEmailsFormValues', () => {
  const mockEmailsResponse: EmailsResponse = {
    groups: [
      {
        group: 'points',
        groupLabel: 'Points',
        items: [
          {
            id: 'points_earned',
            label: 'Points earned',
            description: 'Sent when a customer earns points.',
            recipient: 'customer',
            enabled: true,
            isFree: true,
          },
          {
            id: 'points_expiring_soon',
            label: 'Points expiring soon',
            description: 'Sent before points expire.',
            recipient: 'customer',
            enabled: false,
            isFree: true,
          },
          {
            id: 'expiring_final_reminder',
            label: 'Expiring final reminder',
            description: 'Last-chance reminder.',
            recipient: 'customer',
            enabled: true,
            isFree: false,
          },
          {
            id: 'points_expired',
            label: 'Points expired',
            description: 'Sent after points expire.',
            recipient: 'customer',
            enabled: true,
            isFree: true,
          },
        ],
      },
      {
        group: 'rewards',
        groupLabel: 'Rewards',
        items: [
          {
            id: 'reward_claimed',
            label: 'Reward claimed',
            description: 'Sent when a customer claims a reward.',
            recipient: 'customer',
            enabled: false,
            isFree: false,
          },
        ],
      },
    ],
    sending: {
      from_name: 'Test Shop',
      from_address: 'shop@example.com',
      wc_emails_url: 'http://example.com/wp-admin/admin.php?page=wc-settings&tab=email',
    },
    throttlePerWeek: 3,
  };

  it('extracts enabled state from every group, Free and Pro-only alike', () => {
    const result = toEmailsFormValues(mockEmailsResponse);

    expect(result).toEqual({
      emails: {
        points_earned: true,
        points_expiring_soon: false,
        expiring_final_reminder: true,
        points_expired: true,
        reward_claimed: false,
      },
      throttlePerWeek: 3,
    });
  });

  it('produces a valid form value that passes the schema', () => {
    const result = toEmailsFormValues(mockEmailsResponse);

    expect(() => emailsFormSchema.parse(result)).not.toThrow();
  });

  it('carries the throttle cap through unchanged', () => {
    const result = toEmailsFormValues({ ...mockEmailsResponse, throttlePerWeek: 10 });

    expect(result.throttlePerWeek).toBe(10);
  });

  it('ignores sending data', () => {
    const result = toEmailsFormValues(mockEmailsResponse);

    expect(Object.keys(result)).toEqual(['emails', 'throttlePerWeek']);
  });
});
