import { useFormContext, useWatch } from 'react-hook-form';

import { formatStoreDecimal, parseWPCurrency } from '@/lib/helpers/format.helper';
import type { SetupDraft } from '@/lib/schema/setup.schema';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
  NOTHING_PUBLISHED,
  SPEND_EARN_BACK,
  SPEND_MAX_HELP,
  SPEND_MAX_SUFFIX,
  SPEND_MAX_TITLE,
  SPEND_MIN_HELP,
  SPEND_MIN_TITLE,
  SPEND_OFF,
  SPEND_TITLE,
  SPEND_WORTH_TITLE,
} from './setup-wizard.copy';
import { earnBackPercent, rewardWorth } from './setup-wizard.draft';
import { SetupAmountInput, SetupStepHeading, SetupWholeInput } from './SetupFields';

/** Step 3: the redeem rate, the smallest redemption (priced beside it) and the share of an order points may cover. */
export function SetupStepSpend() {
  const { control } = useFormContext<SetupDraft>();
  const draft = useWatch({ control }) as SetupDraft;
  const minWorth = rewardWorth(draft, draft.reward?.min_points ?? 0);

  return (
    <>
      <SetupStepHeading title={SPEND_TITLE()} description={NOTHING_PUBLISHED()} />

      <Card>
        <CardHeader>
          <CardTitle>{SPEND_WORTH_TITLE()}</CardTitle>
          <CardDescription>{SPEND_EARN_BACK(`${formatStoreDecimal(earnBackPercent(draft), 2)}%`)}</CardDescription>
        </CardHeader>
        <CardContent className="flex flex-wrap items-start gap-2">
          <SetupWholeInput name="reward.points" id="setup-reward-points" min={1} points />
          <span className="pt-1.5 text-sm">=</span>
          <SetupAmountInput name="reward.amount" id="setup-reward-amount" />
          <span className="pt-1.5 text-sm">{SPEND_OFF()}</span>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>{SPEND_MIN_TITLE()}</CardTitle>
          <CardDescription>{SPEND_MIN_HELP()}</CardDescription>
        </CardHeader>
        <CardContent className="flex flex-wrap items-start gap-2">
          <SetupWholeInput name="reward.min_points" id="setup-reward-min" min={0} points />
          <span className="pt-1.5 text-sm">= {parseWPCurrency(minWorth)}</span>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>{SPEND_MAX_TITLE()}</CardTitle>
          <CardDescription>{SPEND_MAX_HELP()}</CardDescription>
        </CardHeader>
        <CardContent className="flex items-start gap-2">
          <SetupWholeInput name="reward.max_percent" id="setup-reward-max" min={1} max={100} />
          <span className="pt-1.5 text-sm">{SPEND_MAX_SUFFIX()}</span>
        </CardContent>
      </Card>
    </>
  );
}
