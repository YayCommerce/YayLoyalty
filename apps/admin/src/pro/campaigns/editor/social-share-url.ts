import type { SocialNetwork } from '@/lib/schema/campaign.schema';

/** Share endpoints per network; the others have no share link, only a profile to follow. */
const SHARE_ENDPOINTS: Partial<Record<SocialNetwork, string>> = {
  facebook: 'https://www.facebook.com/sharer/sharer.php?u=',
  x: 'https://x.com/intent/post?url=',
  linkedin: 'https://www.linkedin.com/sharing/share-offsite/?url=',
  pinterest: 'https://www.pinterest.com/pin/create/button/?url=',
};

/** A link that shares `storeUrl` on `network`, or null when the network has no share link. */
export function socialShareUrl(network: SocialNetwork, storeUrl: string): string | null {
  const endpoint = SHARE_ENDPOINTS[network];

  return endpoint ? endpoint + encodeURIComponent(storeUrl) : null;
}
