import { PlusIcon } from 'lucide-react';
import { useController, useFieldArray, useFormContext, useWatch } from 'react-hook-form';
import { __ } from '@wordpress/i18n';

import { CampaignCondition, CampaignConditionType } from '@/lib/schema/campaign.schema';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { conditionTypeLabel } from '../campaigns/campaign-labels';
import { conditionPath, ConditionsFormValues, defaultConditionValue, firstOperatorFor } from './condition-form';
import { ConditionRow } from './ConditionRow';

/**
 * The `conditions` list of a campaign or reward form, with one all/any for the whole list. At most one
 * entry per type. Per-type value validation runs in the editor's resolver (mergeConditionErrors), not
 * here, so it covers every save path.
 */
export function ConditionListEditor({
  types,
  emptyTitle,
  emptyDescription,
}: {
  /** The vocabulary this editor offers, in order. */
  types: readonly CampaignConditionType[];
  emptyTitle: string;
  emptyDescription: string;
}) {
  const { control } = useFormContext<ConditionsFormValues>();
  const { fields, append, remove } = useFieldArray({ control, name: 'conditions.conditions' });
  const match = useWatch({ control, name: 'conditions.match' });
  const usedTypes = new Set<string>(fields.map((field) => field.type));

  return (
    <div className="flex flex-col gap-4">
      {fields.length === 0 ? (
        <Empty className="py-8">
          <EmptyHeader>
            <EmptyTitle>{emptyTitle}</EmptyTitle>
            <EmptyDescription>{emptyDescription}</EmptyDescription>
          </EmptyHeader>
        </Empty>
      ) : (
        <>
          <div className="flex flex-wrap items-center gap-2 text-sm">
            <span>{__('Applies if', 'yayloyalty')}</span>
            <MatchToggle />
            <span>{__('of these conditions match.', 'yayloyalty')}</span>
          </div>
          <div className="relative flex flex-col gap-3">
            {fields.length > 1 && <span aria-hidden className="bg-border absolute inset-y-6 left-5 w-px" />}
            {fields.map((field, index) => (
              <div key={field.id} className="flex items-start gap-3">
                <div className="relative flex h-12 w-10 shrink-0 items-center justify-center">
                  {index === 0 ? (
                    <span aria-hidden className="border-foreground/60 bg-background size-3.5 rounded-full border-4" />
                  ) : (
                    <span className="bg-muted text-muted-foreground rounded-md px-2 py-0.5 text-xs">
                      {match === 'any' ? __('or', 'yayloyalty') : __('and', 'yayloyalty')}
                    </span>
                  )}
                </div>
                <div className="min-w-0 flex-1">
                  <ConditionRow path={conditionPath(index)} type={field.type} onRemove={() => remove(index)} />
                </div>
              </div>
            ))}
          </div>
        </>
      )}

      <div>
        <AddConditionMenu types={types} usedTypes={usedTypes} onAdd={(type) => append(newCondition(type))} />
      </div>
    </div>
  );
}

function AddConditionMenu({
  types,
  usedTypes,
  onAdd,
}: {
  types: readonly CampaignConditionType[];
  usedTypes: Set<string>;
  onAdd: (type: CampaignConditionType) => void;
}) {
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button type="button" variant="secondary" className="w-full">
          <PlusIcon className="size-4" />
          {__('Add condition', 'yayloyalty')}
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="start">
        {types.map((type) => (
          <DropdownMenuItem key={type} disabled={usedTypes.has(type)} onSelect={() => onAdd(type)}>
            {conditionTypeLabel(type)}
          </DropdownMenuItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

function MatchToggle() {
  const { control } = useFormContext<ConditionsFormValues>();
  const { field } = useController({ control, name: 'conditions.match' });

  return (
    <Segmented
      shape="square"
      size="sm"
      value={field.value}
      onValueChange={(value) => value && field.onChange(value)}
      aria-label={__('Match conditions', 'yayloyalty')}
    >
      <SegmentedItem value="any">{__('Any', 'yayloyalty')}</SegmentedItem>
      <SegmentedItem value="all">{__('All', 'yayloyalty')}</SegmentedItem>
    </Segmented>
  );
}

function newCondition(type: CampaignConditionType): CampaignCondition {
  return { type, operator: firstOperatorFor(type), value: defaultConditionValue(type) };
}
