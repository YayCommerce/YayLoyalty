import { PlusIcon } from 'lucide-react';
import { useController, useFieldArray, useFormContext } from 'react-hook-form';
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
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
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
            <span>{__('Match', 'yayloyalty')}</span>
            <MatchSelect />
            <span>{__('of these conditions', 'yayloyalty')}</span>
          </div>
          <div className="flex flex-col gap-3">
            {fields.map((field, index) => (
              <ConditionRow
                key={field.id}
                path={conditionPath(index)}
                type={field.type}
                onRemove={() => remove(index)}
              />
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
        <Button type="button" variant="outline" size="sm">
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

function MatchSelect() {
  const { control } = useFormContext<ConditionsFormValues>();
  const { field } = useController({ control, name: 'conditions.match' });

  return (
    <Select value={field.value} onValueChange={field.onChange}>
      <SelectTrigger size="sm" className="w-24" aria-label={__('Match conditions', 'yayloyalty')}>
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        <SelectItem value="all">{__('all', 'yayloyalty')}</SelectItem>
        <SelectItem value="any">{__('any', 'yayloyalty')}</SelectItem>
      </SelectContent>
    </Select>
  );
}

function newCondition(type: CampaignConditionType): CampaignCondition {
  return { type, operator: firstOperatorFor(type), value: defaultConditionValue(type) };
}
