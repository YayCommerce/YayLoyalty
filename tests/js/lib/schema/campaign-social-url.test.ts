import { describe, expect, test } from 'vitest';

import { isHttpUrl } from '@/lib/schema/campaign.schema';
import { emptyConditionList } from '@/lib/schema/condition-match.schema';
import { campaignFormResolver } from '@/pro/campaigns/editor/campaign-editor.form';

describe('isHttpUrl @P-7', () => {
  test('accepts the links the server stores', () => {
    expect(isHttpUrl('https://facebook.com')).toBe(true);
    expect(isHttpUrl('https://www.instagram.com/yay-shop')).toBe(true);
    expect(isHttpUrl('http://a-b.co:8080/x?y=1#z')).toBe(true);
  });

  test('refuses the links the server refuses', () => {
    expect(isHttpUrl('')).toBe(false);
    expect(isHttpUrl('https://facebook,com')).toBe(false);
    expect(isHttpUrl('https://café.com')).toBe(false);
    expect(isHttpUrl('https://my_shop.com')).toBe(false);
    expect(isHttpUrl('https://-a.com')).toBe(false);
    expect(isHttpUrl('https://a.com/a b')).toBe(false);
    expect(isHttpUrl('javascript:alert(1)')).toBe(false);
    expect(isHttpUrl('/relative')).toBe(false);
  });
});

describe('campaignFormResolver social link @P-7', () => {
  const values = (url: string) => ({
    name: 'Follow us',
    status: 'active' as const,
    config: { network: 'facebook', action: 'follow', url, points: 20 },
    conditions: emptyConditionList(),
    starts_at: null,
    ends_at: null,
  });
  const options = { fields: {}, shouldUseNativeValidation: false };

  test('refuses to save a link the server would refuse', async () => {
    const result = await campaignFormResolver(values('https://facebook,com'), undefined, options);

    expect(result.errors.config).toBeDefined();
  });

  test('saves a valid link', async () => {
    const result = await campaignFormResolver(values('https://facebook.com'), undefined, options);

    expect(result.errors).toEqual({});
  });
});
