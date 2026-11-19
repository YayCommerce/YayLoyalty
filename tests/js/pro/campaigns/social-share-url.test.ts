import { describe, expect, test } from 'vitest';

import { socialShareUrl } from '@/pro/campaigns/editor/social-share-url';

describe('socialShareUrl @P-7', () => {
  const store = 'https://shop.example/?a=1&b=2';
  const encoded = encodeURIComponent(store);

  test('builds a share link per network that has one', () => {
    expect(socialShareUrl('facebook', store)).toBe(`https://www.facebook.com/sharer/sharer.php?u=${encoded}`);
    expect(socialShareUrl('x', store)).toBe(`https://x.com/intent/post?url=${encoded}`);
    expect(socialShareUrl('linkedin', store)).toBe(`https://www.linkedin.com/sharing/share-offsite/?url=${encoded}`);
    expect(socialShareUrl('pinterest', store)).toBe(`https://www.pinterest.com/pin/create/button/?url=${encoded}`);
  });

  test('is null for follow-only networks', () => {
    expect(socialShareUrl('instagram', store)).toBeNull();
    expect(socialShareUrl('tiktok', store)).toBeNull();
    expect(socialShareUrl('youtube', store)).toBeNull();
  });
});
