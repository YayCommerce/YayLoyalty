import { Link } from 'react-router';

import { formatPoints } from '@/lib/helpers/points.helper';
import { Can, CAP } from '@/lib/access';
import type { ReferralCampaign } from '@/lib/schema/referral.schema';
import { CopyButton } from '@/components/ui/copy-button';
import { campaignSentence, REFERRAL_LINK_LABEL, REFERRAL_SETTINGS_LINK } from '../customer-drawer.referrals.copy';

/**
 * The referral link + Copy button and the campaign sentence, above the
 * table (3f) — kept even though the summary strip is cut, it's the genuine
 * support-call shortcut the tab exists for.
 *
 * "Referral settings" links to the real `/referral` route (confirmed to
 * exist — `pages/referral/ReferralPage.tsx`, registered in `router.tsx`),
 * per the phase's own rule: include it only when the route is real, never
 * ship a dead link. That route is itself gated behind `CAP.MANAGE_CAMPAIGNS`
 * (stricter than the drawer's own `CAP.VIEW`), so the link is wrapped in the
 * same `<Can>` gate `HeaderMenu.tsx` uses for its nav item — otherwise a
 * CAP.VIEW-only viewer would land on a 404 inside the SPA on click, a dead
 * link by capability rather than by missing route.
 */
export function ReferralsTabHeader({
  referralLink,
  campaign,
}: {
  referralLink: string;
  campaign: ReferralCampaign | null;
}) {
  if (!referralLink) return null;

  return (
    <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
      <div className="flex items-center gap-2">
        <span className="text-muted-foreground text-xs">{REFERRAL_LINK_LABEL()}</span>
        <code className="bg-muted rounded px-2 py-1 text-sm">{referralLink}</code>
        <CopyButton content={referralLink} className="size-7" />
      </div>
      <div className="flex items-center gap-3 text-sm">
        {campaign && (
          <span className="text-muted-foreground">
            {campaignSentence(formatPoints(campaign.referrer_points), campaign.friend_coupon_label, campaign.window_days)}
          </span>
        )}
        <Can cap={CAP.MANAGE_CAMPAIGNS}>
          <Link to="/referral" className="text-primary shrink-0 hover:underline">
            {REFERRAL_SETTINGS_LINK()}
          </Link>
        </Can>
      </div>
    </div>
  );
}
