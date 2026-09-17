import { useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';

import { can, CAP } from '@/lib/access';
import { CAMPAIGN_QUERIES } from '@/lib/queries/campaign.queries';

/**
 * `campaign_id → name` for the ledger tab's "Campaign: …" sub-line.
 *
 * `GET /campaigns` requires `MANAGE_CAMPAIGNS`, but the drawer itself is
 * only `VIEW`-gated — a VIEW-only user (e.g. support staff) would otherwise
 * get a guaranteed 403 just for opening the drawer. So this is gated with
 * `enabled` rather than called unconditionally, and degrades to an empty
 * Map instead of surfacing an error: no map means no campaign sub-line,
 * never a drawer-wide error state over one missing decoration.
 *
 * Accepted debt (revisit when Export ledger ships — brief §"campaign_name"):
 * - Export ledger is a server-side background job with no access to this
 *   client-side map, so exported rows carry no campaign name.
 * - Archived/deleted campaigns may not appear in `GET /campaigns` — an old
 *   ledger row's campaign name can go missing even for a MANAGE_CAMPAIGNS user.
 * - `GET /campaigns` is itself paginated; `perPage: 100` is best-effort, not
 *   a guarantee every campaign_id resolves.
 */
export function useLedgerCampaignNames(): Map<number, string> {
  const query = useQuery({
    ...CAMPAIGN_QUERIES.list({ perPage: 100 }),
    enabled: can(CAP.MANAGE_CAMPAIGNS),
  });

  return useMemo(() => {
    if (!query.data) return new Map<number, string>();

    return new Map(query.data.data.map((campaign) => [campaign.id, campaign.name]));
  }, [query.data]);
}
