import { useQuery } from '@tanstack/react-query';
import { CheckIcon } from 'lucide-react';
import { useFormContext, useWatch } from 'react-hook-form';

import { can, CAP } from '@/lib/access';
import { parseWPCurrency, storeCurrency } from '@/lib/helpers/format.helper';
import { formatPoints } from '@/lib/helpers/points.helper';
import { CAMPAIGN_QUERIES } from '@/lib/queries/campaign.queries';
import { REWARD_QUERIES } from '@/lib/queries/reward.queries';
import type { SetupDraft } from '@/lib/schema/setup.schema';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { launcherPositionLabel } from '../settings/design-settings.option-labels';
import * as copy from './setup-wizard.copy';
import { SetupStepHeading } from './SetupFields';

/** Enough rows for every built-in campaign, whatever a Pro store has added around them. */
const LIST_PARAMS = { perPage: 100 };

/** Step 5: every answer with a link back to its step, what Turn on creates, and what it changes for customers. */
export function SetupStepReview({ onEdit }: { onEdit: (step: number) => void }) {
  const { control } = useFormContext<SetupDraft>();
  const draft = useWatch({ control }) as SetupDraft;
  const money = (amount: string) => parseWPCurrency(Number(amount));
  // The merchant's own words from step 1, singular at exactly 1, as the storefront reads them.
  const label = (points: number) => (points === 1 ? draft.points_label_singular : draft.points_label_plural);
  const counted = (points: number) => [formatPoints(points), label(points)] as const;
  const rows: { label: string; value: string; step: number }[] = [
    {
      label: copy.REVIEW_NAMES_LABEL(),
      value: `${draft.points_label_singular} · ${draft.points_label_plural} · ${storeCurrency()}`,
      step: 1,
    },
    {
      label: copy.REVIEW_EARN_LABEL(),
      value: copy.REVIEW_EARN_VALUE(...counted(draft.earn.points), money(draft.earn.amount)),
      step: 2,
    },
    {
      label: copy.REVIEW_SIGNUP_LABEL(),
      value: draft.signup.enabled ? copy.REVIEW_SIGNUP_VALUE(...counted(draft.signup.points)) : copy.REVIEW_OFF(),
      step: 2,
    },
    {
      label: copy.REVIEW_REVIEW_LABEL(),
      value: draft.review.enabled ? copy.REVIEW_REVIEW_VALUE(...counted(draft.review.points)) : copy.REVIEW_OFF(),
      step: 2,
    },
    {
      label: copy.REVIEW_WORTH_LABEL(),
      value: copy.REVIEW_WORTH_VALUE(...counted(draft.reward.points), money(draft.reward.amount)),
      step: 3,
    },
    {
      label: copy.REVIEW_LIMITS_LABEL(),
      value: copy.REVIEW_LIMITS_VALUE(...counted(draft.reward.min_points), draft.reward.max_percent),
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

/**
 * The built-ins Turn on writes, by their current names. No links out: leaving from here would drop unsaved
 * edits before the program is live; the dashboard hands over to those screens afterwards.
 */
function WhatThisCreates() {
  // Only users who may open each list ask for it; anyone else sees the seeded names.
  const campaigns = useQuery({ ...CAMPAIGN_QUERIES.list(LIST_PARAMS), enabled: can(CAP.MANAGE_CAMPAIGNS) });
  const rewards = useQuery({ ...REWARD_QUERIES.list(LIST_PARAMS), enabled: can(CAP.MANAGE_REWARDS) });
  const names = campaigns.data?.data.filter((campaign) => campaign.is_builtin).map((campaign) => campaign.name);
  const rewardName = rewards.data?.data.find((reward) => reward.is_builtin)?.name;

  return (
    <Card>
      <CardHeader>
        <CardTitle>{copy.REVIEW_CREATES_TITLE()}</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-4 text-sm">
        <CreatedRow
          label={copy.REVIEW_CAMPAIGNS_LABEL()}
          value={names?.length ? names.join(' · ') : copy.REVIEW_CAMPAIGNS_DEFAULT_NAMES()}
          help={copy.REVIEW_CAMPAIGNS_HELP()}
        />
        <CreatedRow
          label={copy.REVIEW_REWARDS_LABEL()}
          value={rewardName ?? copy.REVIEW_REWARD_DEFAULT_NAME()}
          help={copy.REVIEW_REWARDS_HELP()}
        />
      </CardContent>
    </Card>
  );
}

function CreatedRow({ label, value, help }: { label: string; value: string; help: string }) {
  return (
    <div className="flex flex-col gap-1">
      <span className="font-medium">{label}</span>
      <span>{value}</span>
      <span className="text-muted-foreground">{help}</span>
    </div>
  );
}
