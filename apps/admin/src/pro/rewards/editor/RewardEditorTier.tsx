import { useEffect } from 'react';
import { InfoIcon, TriangleAlert } from 'lucide-react';
import { useFormContext, useWatch } from 'react-hook-form';
import { __ } from '@wordpress/i18n';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { ErrorState } from '@/components/ui/custom/error-state';
import { Field, FieldContent, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { TierIconChip } from '../../tiers/TierIconChip';
import { useTiersEnabled, useTiersQuery } from '../../tiers/tiers.queries';
import { RewardFormValues, TierRule } from './reward-editor.form';

const EDIT = { shouldDirty: true, shouldValidate: true } as const;

const TIER_RULE_LABELS: Record<TierRule, string> = {
  any: __('Any tier', 'yayloyalty'),
  gte: __('Minimum tier', 'yayloyalty'),
  eq: __('Only this tier', 'yayloyalty'),
};

const TIER_RULE_DESCRIPTIONS: Partial<Record<TierRule, string>> = {
  gte: __('Only customers in this tier or a higher one see and can claim this reward.', 'yayloyalty'),
  eq: __('Only customers in this tier see and can claim this reward.', 'yayloyalty'),
};

/** The tier rule as tabs, then the tier it names; a deleted tier is cleared so the save is not refused. */
export function RewardEditorTier() {
  const { control, setValue, formState } = useFormContext<RewardFormValues>();
  const tierRule = useWatch({ control, name: 'tier_rule' });
  const tierId = useWatch({ control, name: 'tier_id' });
  const tiersQuery = useTiersQuery();
  const tiersEnabled = useTiersEnabled();
  const tiers = tiersQuery.data;
  const isRemoved = tiers !== undefined && tierId !== null && !tiers.some((tier) => tier.id === tierId);
  const storedId = formState.defaultValues?.tier_id ?? null;
  const wasRemoved = tiers !== undefined && storedId !== null && !tiers.some((tier) => tier.id === storedId);
  const description = TIER_RULE_DESCRIPTIONS[tierRule];

  // The server refuses a save naming a deleted tier, so a removed one is cleared; dirtying it lets Save write that.
  useEffect(() => {
    if (isRemoved) {
      setValue('tier_rule', 'any', EDIT);
      setValue('tier_id', null, EDIT);
    }
  }, [isRemoved, setValue]);

  const changeRule = (next: TierRule) => {
    setValue('tier_rule', next, EDIT);

    if (next === 'any') {
      setValue('tier_id', null, EDIT);
    }
  };

  return (
    <>
      {!tiersEnabled && (
        <Alert variant="outline">
          <InfoIcon />
          <AlertDescription>
            {__(
              'Tiers are off. Rewards that require a tier are hidden from every customer until you turn tiers on.',
              'yayloyalty',
            )}
          </AlertDescription>
        </Alert>
      )}

      <Field>
        <FieldLabel htmlFor="reward-tier-rule">{__('Tier rule', 'yayloyalty')}</FieldLabel>
        <FieldContent>
          <Segmented
            id="reward-tier-rule"
            value={tierRule}
            // Re-clicking the active item yields an empty string; never write that back.
            onValueChange={(next) => next && changeRule(next as TierRule)}
            disabled={tiersQuery.isPending || tiersQuery.isError}
            className="w-max max-w-full"
          >
            {(Object.keys(TIER_RULE_LABELS) as TierRule[]).map((rule) => (
              <SegmentedItem key={rule} value={rule}>
                {TIER_RULE_LABELS[rule]}
              </SegmentedItem>
            ))}
          </Segmented>
          {tiersQuery.isError && (
            <ErrorState
              message={__('Could not load the tiers.', 'yayloyalty')}
              onRetry={() => void tiersQuery.refetch()}
            />
          )}
          {wasRemoved && tierRule === 'any' && (
            <Alert variant="warning">
              <TriangleAlert />
              <AlertDescription>{__('The tier this reward required was removed.', 'yayloyalty')}</AlertDescription>
            </Alert>
          )}
        </FieldContent>
      </Field>

      {tierRule !== 'any' && (
        <Field>
          <FieldLabel htmlFor="reward-tier-id">{__('Tier', 'yayloyalty')}</FieldLabel>
          <FieldContent>
            <Select
              value={tierId === null ? '' : String(tierId)}
              onValueChange={(next) => setValue('tier_id', Number(next), EDIT)}
              disabled={tiersQuery.isPending || tiersQuery.isError}
            >
              <SelectTrigger id="reward-tier-id" className="w-full" aria-invalid={!!formState.errors.tier_id}>
                <SelectValue placeholder={__('Select a tier', 'yayloyalty')} />
              </SelectTrigger>
              <SelectContent>
                {tiers?.map((tier) => (
                  <SelectItem key={tier.id} value={String(tier.id)}>
                    <span className="flex items-center gap-2">
                      <TierIconChip color={tier.color} size="sm" />
                      {tier.name}
                    </span>
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <FieldError errors={[formState.errors.tier_id]} />
            {description && <FieldDescription>{description}</FieldDescription>}
          </FieldContent>
        </Field>
      )}
    </>
  );
}
