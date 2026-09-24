import { describe, expect, it } from 'vitest';

import { emailsFormSchema, toEmailsFormValues, type EmailItem, type EmailsResponse } from '@/lib/schema/emails.schema';

describe('emailsFormSchema', () => {
  it('accepts whichever ids the server registered (bare string keys, not a closed enum)', () => {
    const result = emailsFormSchema.parse({
      emails: {
        yayloyalty_points_earned: true,
        yayloyalty_reward_claimed: false,
      },
      throttlePerWeek: 3,
    });

    expect(result.emails.yayloyalty_points_earned).toBe(true);
    expect(result.emails.yayloyalty_reward_claimed).toBe(false);
  });

  it('accepts any subset of ids (record allows partial)', () => {
    const result = emailsFormSchema.parse({ emails: { yayloyalty_points_earned: true }, throttlePerWeek: 3 });

    expect(result.emails).toEqual({ yayloyalty_points_earned: true });
  });

  it('rejects non-boolean values', () => {
    expect(() =>
      emailsFormSchema.parse({ emails: { yayloyalty_points_earned: 'true' }, throttlePerWeek: 3 }),
    ).toThrow();
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

function item(id: string, enabled: boolean): EmailItem {
  return {
    id,
    title: id,
    description: `${id} description`,
    recipient: 'customer',
    enabled,
    editUrl: `http://example.com/wp-admin/admin.php?page=wc-settings&tab=email&section=${id}`,
  };
}

function response(groups: EmailsResponse['groups'], throttlePerWeek = 3): EmailsResponse {
  return {
    groups,
    sending: {
      from_name: 'Test Shop',
      from_address: 'shop@example.com',
      wc_emails_url: 'http://example.com/wp-admin/admin.php?page=wc-settings&tab=email',
    },
    throttlePerWeek,
  };
}

/** Lite registers 3 emails in one group; Pro 12 across five. The form covers whatever arrived. */
const LITE = response([
  {
    group: 'yayloyalty_points',
    groupLabel: 'Loyalty: Points',
    items: [
      item('yayloyalty_points_earned', true),
      item('yayloyalty_points_expiring_soon', true),
      item('yayloyalty_points_expired', false),
    ],
  },
]);

const PRO = response([
  { ...LITE.groups[0], items: [...LITE.groups[0].items, item('yayloyalty_expiring_final_reminder', true)] },
  {
    group: 'yayloyalty_rewards',
    groupLabel: 'Loyalty: Rewards',
    items: [item('yayloyalty_reward_claimed', true), item('yayloyalty_coupon_issued', true)],
  },
  {
    group: 'yayloyalty_referral',
    groupLabel: 'Loyalty: Referral',
    items: [item('yayloyalty_invite_sent', true), item('yayloyalty_referral_reward_earned', true)],
  },
  {
    group: 'yayloyalty_tiers',
    groupLabel: 'Loyalty: Tiers',
    items: [
      item('yayloyalty_tier_reached', true),
      item('yayloyalty_tier_ending_soon', true),
      item('yayloyalty_tier_lost', false),
    ],
  },
  { group: 'yayloyalty_birthday', groupLabel: 'Loyalty: Birthday', items: [item('yayloyalty_birthday_points', true)] },
]);

describe('toEmailsFormValues', () => {
  it('keeps every registered id with its enabled state (Lite, 3 emails)', () => {
    expect(toEmailsFormValues(LITE).emails).toEqual({
      yayloyalty_points_earned: true,
      yayloyalty_points_expiring_soon: true,
      yayloyalty_points_expired: false,
    });
  });

  it('keeps every registered id across all groups (Pro, 12 emails)', () => {
    const emails = toEmailsFormValues(PRO).emails;

    expect(Object.keys(emails)).toHaveLength(12);
    expect(emails.yayloyalty_tier_lost).toBe(false);
    expect(emails.yayloyalty_birthday_points).toBe(true);
  });

  it('produces a valid form value that passes the schema', () => {
    expect(() => emailsFormSchema.parse(toEmailsFormValues(PRO))).not.toThrow();
  });

  it('carries the throttle cap through unchanged', () => {
    expect(toEmailsFormValues(response(LITE.groups, 10)).throttlePerWeek).toBe(10);
  });

  it('posts back only toggles and the cap, never titles, links or sender info', () => {
    expect(Object.keys(toEmailsFormValues(PRO))).toEqual(['emails', 'throttlePerWeek']);
  });
});
