import { XIcon } from '@phosphor-icons/react';
import { Link } from 'react-router';

import { Can, CAP, type Capability } from '@/lib/access';
import { toastApiError } from '@/lib/api/api';
import { parseWPCurrency } from '@/lib/helpers/format.helper';
import { formatPoints, spendPairFromRate } from '@/lib/helpers/points.helper';
import { useDismissFirstRunMutation, useSetupBuiltinNames, useSetupQuery } from '@/lib/queries/setup.queries';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { REVIEW_CAMPAIGNS_DEFAULT_NAMES } from '../../setup/setup-wizard.copy';
import { pointsLabelFor } from '../../setup/setup-wizard.draft';
import {
  FIRST_RUN_BODY,
  FIRST_RUN_DISMISS,
  FIRST_RUN_DISMISS_ERROR,
  FIRST_RUN_STOREFRONT,
  FIRST_RUN_TITLE,
  PROGRAM_CARD_CAMPAIGNS,
  PROGRAM_CARD_REWARD,
  PROGRAM_CARD_REWARDS,
  PROGRAM_CARD_SETTINGS,
  PROGRAM_CARD_TITLE,
} from '../dashboard-page.copy';

const LINKS: { to: string; cap: Capability; label: () => string }[] = [
  { to: '/campaigns', cap: CAP.MANAGE_CAMPAIGNS, label: PROGRAM_CARD_CAMPAIGNS },
  { to: '/rewards', cap: CAP.MANAGE_REWARDS, label: PROGRAM_CARD_REWARDS },
  { to: '/settings', cap: CAP.MANAGE_SETTINGS, label: PROGRAM_CARD_SETTINGS },
];

/**
 * Right after "Turn on my program", until dismissed: the live banner and what the program now runs.
 * Readable by any YayLoyalty user: the rate and labels come from GET /setup, which needs only the view cap.
 * Dismissing either hides both.
 */
export function DashboardFirstRun() {
  const dismiss = useDismissFirstRunMutation();
  const setup = useSetupQuery();
  const names = useSetupBuiltinNames();

  if (dismiss.isPending || dismiss.isSuccess) {
    return null;
  }

  const onDismiss = () =>
    dismiss.mutate(undefined, { onError: (error) => void toastApiError(error, FIRST_RUN_DISMISS_ERROR()) });
  const live = setup.data?.live;
  const pair = live ? spendPairFromRate(live.reward.points_per_currency_unit) : null;

  return (
    <>
      <div
        role="status"
        className="bg-card border-l-foreground relative flex flex-col gap-2 rounded-md border border-l-4 p-4 pr-10"
      >
        <div className="text-sm font-semibold">{FIRST_RUN_TITLE()}</div>
        <div className="text-muted-foreground text-xs">{FIRST_RUN_BODY()}</div>
        <div>
          <Button asChild variant="link" size="sm" className="h-auto p-0 text-xs">
            <a href={window.yayLoyaltyMeta.wpMeta.homeUrl} target="_blank" rel="noreferrer">
              {FIRST_RUN_STOREFRONT()}
            </a>
          </Button>
        </div>
        <DismissButton onDismiss={onDismiss} />
      </div>

      <Card className="relative">
        <CardHeader>
          <CardTitle>{PROGRAM_CARD_TITLE()}</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-2 text-sm">
          <span>{names.campaigns?.length ? names.campaigns.join(' · ') : REVIEW_CAMPAIGNS_DEFAULT_NAMES()}</span>
          {live && pair && (
            <span>
              {PROGRAM_CARD_REWARD(
                formatPoints(pair.points),
                pointsLabelFor(pair.points, live),
                parseWPCurrency(pair.spend),
              )}
            </span>
          )}
          <div className="flex flex-wrap gap-3">
            {LINKS.map(({ to, cap, label }) => (
              <Can key={to} cap={cap}>
                <Button asChild variant="link" size="sm" className="h-auto p-0 text-xs">
                  <Link to={to}>{label()}</Link>
                </Button>
              </Can>
            ))}
          </div>
        </CardContent>
        <DismissButton onDismiss={onDismiss} />
      </Card>
    </>
  );
}

function DismissButton({ onDismiss }: { onDismiss: () => void }) {
  return (
    <Button
      variant="ghost"
      size="icon-sm"
      className="absolute top-2 right-2"
      aria-label={FIRST_RUN_DISMISS()}
      onClick={onDismiss}
    >
      <XIcon />
    </Button>
  );
}
