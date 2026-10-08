import { Controller, useFormContext, useWatch } from 'react-hook-form';

import { parseWPCurrency } from '@/lib/helpers/format.helper';
import { formatPoints } from '@/lib/helpers/points.helper';
import type { SetupDraft } from '@/lib/schema/setup.schema';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Switch } from '@/components/ui/switch';
import {
  CAMPAIGNS_NOTE,
  EARN_BASE_HELP,
  EARN_FOR_EVERY,
  EARN_POINTS_SUFFIX,
  EARN_RATE_TITLE,
  EARN_SPENT,
  EARN_TIMING,
  EARN_TITLE,
  EARN_WORKED,
  NOTHING_PUBLISHED,
  POINTS_UNIT,
  REVIEW_HELP,
  REVIEW_TITLE,
  SIGNUP_HELP,
  SIGNUP_TITLE,
  SUGGESTED_TITLE,
} from './setup-wizard.copy';
import { earnedOn } from './setup-wizard.draft';
import { SetupAmountInput, SetupStepHeading, SetupWholeInput } from './SetupFields';

/** The order total the worked line prices: a rate is abstract, a 50 order is not. */
const WORKED_ORDER_TOTAL = 50;

/** Step 2: the purchase rate with its worked line, then signup and review as switchable suggestions. */
export function SetupStepEarn() {
  const { control } = useFormContext<SetupDraft>();
  const draft = useWatch({ control }) as SetupDraft;
  const earned = earnedOn(draft, WORKED_ORDER_TOTAL);
  const label = earned === 1 ? draft.points_label_singular : draft.points_label_plural;

  return (
    <>
      <SetupStepHeading title={EARN_TITLE()} description={NOTHING_PUBLISHED()} />

      <Card>
        <CardHeader>
          <CardTitle>{EARN_RATE_TITLE()}</CardTitle>
          <CardDescription>{EARN_BASE_HELP()}</CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-3">
          <div className="flex flex-wrap items-start gap-2">
            <SetupWholeInput name="earn.points" id="setup-earn-points" min={1} />
            <span className="pt-1.5 text-sm">{EARN_POINTS_SUFFIX(draft.earn?.points ?? 0)}</span>
            <span className="pt-1.5 text-sm">{EARN_FOR_EVERY()}</span>
            <SetupAmountInput name="earn.amount" id="setup-earn-amount" />
            <span className="pt-1.5 text-sm">{EARN_SPENT()}</span>
          </div>
          <div className="bg-muted rounded-md p-3 text-sm font-medium">
            {EARN_WORKED(parseWPCurrency(WORKED_ORDER_TOTAL, 0), formatPoints(earned), label)}
          </div>
          <p className="text-muted-foreground text-sm">{EARN_TIMING()}</p>
        </CardContent>
      </Card>

      <h2 className="text-base font-semibold">{SUGGESTED_TITLE()}</h2>
      <SuggestedCampaign group="signup" title={SIGNUP_TITLE()} help={SIGNUP_HELP()} />
      <SuggestedCampaign group="review" title={REVIEW_TITLE()} help={REVIEW_HELP()} />
      <p className="text-muted-foreground text-sm">{CAMPAIGNS_NOTE()}</p>
    </>
  );
}

/** A suggestion that arrives on: switching it off pauses the campaign on Turn on, it deletes nothing. */
function SuggestedCampaign({ group, title, help }: { group: 'signup' | 'review'; title: string; help: string }) {
  const { control } = useFormContext<SetupDraft>();
  const isOn = useWatch({ control, name: `${group}.enabled` });

  return (
    <Card>
      <CardContent className="flex items-start gap-4">
        <Controller
          control={control}
          name={`${group}.enabled`}
          render={({ field }) => <Switch checked={field.value} onCheckedChange={field.onChange} aria-label={title} />}
        />
        <div className="flex flex-1 flex-col gap-1">
          <span className="font-medium">{title}</span>
          <span className="text-muted-foreground text-sm">{help}</span>
        </div>
        <SetupWholeInput
          name={`${group}.points`}
          id={`setup-${group}-points`}
          min={0}
          unit={POINTS_UNIT()}
          disabled={!isOn}
        />
      </CardContent>
    </Card>
  );
}
