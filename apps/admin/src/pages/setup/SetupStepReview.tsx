import { useQuery } from '@tanstack/react-query';
import { CheckIcon } from 'lucide-react';
import { useFormContext, useWatch } from 'react-hook-form';
import { Link } from 'react-router';

import { can, CAP } from '@/lib/access';
import { parseWPCurrency, storeCurrency } from '@/lib/helpers/format.helper';
import { formatPoints } from '@/lib/helpers/points.helper';
import { CAMPAIGN_QUERIES } from '@/lib/queries/campaign.queries';
import type { SetupDraft } from '@/lib/schema/setup.schema';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { launcherPositionLabel } from '../settings/design-settings.option-labels';
import * as copy from './setup-wizard.copy';
import { SetupStepHeading } from './SetupFields';

/** Enough rows for every built-in campaign, whatever a Pro store has added around them. */
const CAMPAIGN_LIST_PARAMS = { perPage: 100 };

/** Step 5: every answer with a link back to its step, what Turn on creates, and what it changes for customers. */
export function SetupStepReview({ onEdit }: { onEdit: (step: number) => void }) {
  const { control } = useFormContext<SetupDraft>();
  const draft = useWatch({ control }) as SetupDraft;
  const money = (amount: string) => parseWPCurrency(Number(amount));
  const rows: { label: string; value: string; step: number }[] = [
    {
      label: copy.REVIEW_NAMES_LABEL(),
      value: `${draft.points_label_singular} · ${draft.points_label_plural} · ${storeCurrency()}`,
      step: 1,
    },
    {
      label: copy.REVIEW_EARN_LABEL(),
      value: copy.REVIEW_EARN_VALUE(formatPoints(draft.earn.points), money(draft.earn.amount)),
      step: 2,
    },
    {
      label: copy.REVIEW_SIGNUP_LABEL(),
      value: draft.signup.enabled ? copy.REVIEW_SIGNUP_VALUE(formatPoints(draft.signup.points)) : copy.REVIEW_OFF(),
      step: 2,
    },
    {
      label: copy.REVIEW_REVIEW_LABEL(),
      value: draft.review.enabled ? copy.REVIEW_REVIEW_VALUE(formatPoints(draft.review.points)) : copy.REVIEW_OFF(),
      step: 2,
    },
    {
      label: copy.REVIEW_WORTH_LABEL(),
      value: copy.REVIEW_WORTH_VALUE(formatPoints(draft.reward.points), money(draft.reward.amount)),
      step: 3,
    },
    {
      label: copy.REVIEW_LIMITS_LABEL(),
      value: copy.REVIEW_LIMITS_VALUE(formatPoints(draft.reward.min_points), draft.reward.max_percent),
      step: 3,
    },
    {
      label: copy.REVIEW_LAUNCHER_LABEL(),
      value: `${launcherPositionLabel(draft.launcher.position)}, ${draft.launcher.brand_color.toUpperCase()}`,
      step: 4,
    },
  ];

  return (
    <>
      <SetupStepHeading title={copy.REVIEW_STEP_TITLE()} description={copy.REVIEW_STEP_HELP()} />

      <Card>
        <CardHeader>
          <CardTitle>{copy.REVIEW_PROGRAM_TITLE()}</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col divide-y">
          {rows.map((row) => (
            <div key={row.label} className="flex items-center gap-4 py-2">
              <span className="text-muted-foreground w-40 shrink-0 text-sm">{row.label}</span>
              <span className="flex-1 text-sm font-medium">{row.value}</span>
              <Button type="button" variant="link" size="sm" onClick={() => onEdit(row.step)}>
                {copy.REVIEW_EDIT()}
              </Button>
            </div>
          ))}
        </CardContent>
      </Card>

      <WhatThisCreates />

      <Card>
        <CardHeader>
          <CardTitle>{copy.REVIEW_WHEN_ON_TITLE()}</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-2 text-sm">
          {[copy.REVIEW_WHEN_EARN(), copy.REVIEW_WHEN_LAUNCHER()].map((line) => (
            <div key={line} className="flex items-start gap-2">
              <CheckIcon className="text-primary mt-0.5 size-4 shrink-0" aria-hidden />
              <span>{line}</span>
            </div>
          ))}
        </CardContent>
      </Card>
    </>
  );
}

/** The built-ins Turn on writes, by their current names, with links to the screens that own them from now on. */
function WhatThisCreates() {
  // Only users who may open Campaigns ask for it; anyone else sees the seeded names.
  const campaigns = useQuery({ ...CAMPAIGN_QUERIES.list(CAMPAIGN_LIST_PARAMS), enabled: can(CAP.MANAGE_CAMPAIGNS) });
  const names = campaigns.data?.data.filter((campaign) => campaign.is_builtin).map((campaign) => campaign.name);

  return (
    <Card>
      <CardHeader>
        <CardTitle>{copy.REVIEW_CREATES_TITLE()}</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-4 text-sm">
        <CreatedRow
          label={copy.REVIEW_CAMPAIGNS_LABEL()}
          to="/campaigns"
          value={names?.length ? names.join(' · ') : copy.REVIEW_CAMPAIGNS_DEFAULT_NAMES()}
          help={copy.REVIEW_CAMPAIGNS_HELP()}
        />
        <CreatedRow
          label={copy.REVIEW_REWARDS_LABEL()}
          to="/rewards"
          value={copy.REVIEW_REWARD_NAME()}
          help={copy.REVIEW_REWARDS_HELP()}
        />
      </CardContent>
    </Card>
  );
}

function CreatedRow({ label, to, value, help }: { label: string; to: string; value: string; help: string }) {
  return (
    <div className="flex flex-col gap-1">
      <div className="flex items-center gap-2">
        <span className="font-medium">{label}</span>
        <Link to={to} className="text-primary ml-auto text-sm hover:underline">
          {copy.REVIEW_OPEN_SCREEN()}
        </Link>
      </div>
      <span>{value}</span>
      <span className="text-muted-foreground">{help}</span>
    </div>
  );
}
