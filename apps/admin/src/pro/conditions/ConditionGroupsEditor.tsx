import { ReactNode, useEffect, useId, useRef, useState } from 'react';
import { PlusIcon, Trash2Icon } from 'lucide-react';
import { useController, useFieldArray, useFormContext, useWatch } from 'react-hook-form';
import { __, sprintf } from '@wordpress/i18n';

import { CampaignCondition, CampaignConditionType } from '@/lib/schema/campaign.schema';
import { ConditionMatch, MAX_CONDITION_GROUPS } from '@/lib/schema/condition-groups.schema';
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
 * The `conditions` tree of a campaign or reward form: one group shows as a plain list with its own
 * all/any, two or more as group cards under a top all/any. At most one entry per type in a group.
 * Per-type value validation runs in the editor's resolver (mergeConditionErrors), not here, so it
 * covers every save path. Empty groups are kept in form state and dropped by the server on save.
 */
export function ConditionGroupsEditor({
  types,
  emptyTitle,
  emptyDescription,
}: {
  /** The vocabulary this editor offers, in order. */
  types: readonly CampaignConditionType[];
  emptyTitle: string;
  emptyDescription: string;
}) {
  const idPrefix = useId();
  const { control } = useFormContext<ConditionsFormValues>();
  const { fields, append, remove } = useFieldArray({ control, name: 'conditions.groups' });
  const rootMatch = useWatch({ control, name: 'conditions.match' });
  const addGroupRef = useRef<HTMLButtonElement>(null);
  const [focusTarget, setFocusTarget] = useState<number | 'add-group' | null>(null);

  useEffect(() => {
    if (focusTarget === null) {
      return;
    }

    const target =
      focusTarget === 'add-group'
        ? addGroupRef.current
        : document.getElementById(addConditionId(idPrefix, focusTarget));

    target?.focus();
    setFocusTarget(null);
  }, [focusTarget, fields.length, idPrefix]);

  const isGrouped = fields.length > 1;
  const atCap = fields.length >= MAX_CONDITION_GROUPS;

  function handleAddGroup() {
    const missing = fields.length === 0 ? 2 : 1;

    append(Array.from({ length: missing }, () => ({ match: 'all' as ConditionMatch, conditions: [] })));
    setFocusTarget(fields.length + missing - 1);
  }

  function handleRemoveGroup(groupIndex: number) {
    remove(groupIndex);
    setFocusTarget('add-group');
  }

  const addGroupButton = (
    <Button ref={addGroupRef} type="button" variant="outline" size="sm" disabled={atCap} onClick={handleAddGroup}>
      <PlusIcon className="size-4" />
      {__('Add group', 'yayloyalty')}
    </Button>
  );

  if (!isGrouped) {
    return (
      <SingleGroup
        idPrefix={idPrefix}
        types={types}
        hasGroup={fields.length === 1}
        onAddFirst={(condition) => append({ match: 'all', conditions: [condition] })}
        emptyTitle={emptyTitle}
        emptyDescription={emptyDescription}
        addGroupButton={addGroupButton}
      />
    );
  }

  const separator = rootMatch === 'any' ? __('OR', 'yayloyalty') : __('AND', 'yayloyalty');

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center gap-2 text-sm">
        <span>{__('Customers must match', 'yayloyalty')}</span>
        <MatchSelect name="conditions.match" label={__('Match groups', 'yayloyalty')} />
        <span>{__('of these groups', 'yayloyalty')}</span>
      </div>

      <div className="flex flex-col gap-2">
        {fields.map((field, groupIndex) => (
          <div key={field.id} className="flex flex-col gap-2">
            {groupIndex > 0 && (
              <div aria-hidden="true" className="text-muted-foreground flex items-center gap-2 text-xs">
                <span className="bg-border h-px flex-1" />
                {separator}
                <span className="bg-border h-px flex-1" />
              </div>
            )}
            <GroupCard
              idPrefix={idPrefix}
              groupIndex={groupIndex}
              types={types}
              onRemove={() => handleRemoveGroup(groupIndex)}
            />
          </div>
        ))}
      </div>

      <div className="flex flex-wrap items-center justify-between gap-2">
        {addGroupButton}
        {atCap && <p className="text-muted-foreground text-xs">{__('Up to 10 groups.', 'yayloyalty')}</p>}
      </div>
    </div>
  );
}

/** Zero or one group: today's list, with "Match [all] of these conditions" once there is a condition. */
function SingleGroup({
  idPrefix,
  types,
  hasGroup,
  onAddFirst,
  emptyTitle,
  emptyDescription,
  addGroupButton,
}: {
  idPrefix: string;
  types: readonly CampaignConditionType[];
  hasGroup: boolean;
  onAddFirst: (condition: CampaignCondition) => void;
  emptyTitle: string;
  emptyDescription: string;
  addGroupButton: ReactNode;
}) {
  const { control } = useFormContext<ConditionsFormValues>();
  const conditions = useWatch({ control, name: 'conditions.groups.0.conditions' });
  const isEmpty = !hasGroup || !conditions || conditions.length === 0;

  return (
    <div className="flex flex-col gap-4">
      {isEmpty && (
        <Empty className="py-8">
          <EmptyHeader>
            <EmptyTitle>{emptyTitle}</EmptyTitle>
            <EmptyDescription>{emptyDescription}</EmptyDescription>
          </EmptyHeader>
        </Empty>
      )}

      {hasGroup ? (
        <GroupConditions
          idPrefix={idPrefix}
          groupIndex={0}
          types={types}
          header={
            isEmpty ? null : (
              <div className="flex flex-wrap items-center gap-2 text-sm">
                <span>{__('Match', 'yayloyalty')}</span>
                <MatchSelect name="conditions.groups.0.match" label={__('Match conditions', 'yayloyalty')} />
                <span>{__('of these conditions', 'yayloyalty')}</span>
              </div>
            )
          }
          extraAction={addGroupButton}
        />
      ) : (
        <div className="flex flex-wrap items-center gap-2">
          <AddConditionMenu
            id={addConditionId(idPrefix, 0)}
            types={types}
            usedTypes={new Set()}
            onAdd={(type) => onAddFirst(newCondition(type))}
          />
          {addGroupButton}
        </div>
      )}
    </div>
  );
}

/** One group as a fieldset card, in the two-or-more layout. */
function GroupCard({
  idPrefix,
  groupIndex,
  types,
  onRemove,
}: {
  idPrefix: string;
  groupIndex: number;
  types: readonly CampaignConditionType[];
  onRemove: () => void;
}) {
  const { control } = useFormContext<ConditionsFormValues>();
  const conditions = useWatch({ control, name: `conditions.groups.${groupIndex}.conditions` });
  /* translators: %d: the group's position. */
  const legend = sprintf(__('Group %d', 'yayloyalty'), groupIndex + 1);

  return (
    <fieldset className="rounded-lg border p-3">
      <legend className="px-1 text-sm font-medium">{legend}</legend>
      <div className="flex flex-col gap-3">
        <div className="flex flex-wrap items-center justify-end gap-2 text-sm">
          <span>{__('Match', 'yayloyalty')}</span>
          <MatchSelect
            name={`conditions.groups.${groupIndex}.match`}
            /* translators: %d: the group's position. */
            label={sprintf(__('Group %d match', 'yayloyalty'), groupIndex + 1)}
          />
          <span>{__('of these', 'yayloyalty')}</span>
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            /* translators: %d: the group's position. */
            aria-label={sprintf(__('Remove group %d', 'yayloyalty'), groupIndex + 1)}
            onClick={onRemove}
          >
            <Trash2Icon className="size-4" />
          </Button>
        </div>

        {(!conditions || conditions.length === 0) && (
          <p className="text-muted-foreground text-xs">{__('Empty groups are removed when you save.', 'yayloyalty')}</p>
        )}

        <GroupConditions idPrefix={idPrefix} groupIndex={groupIndex} types={types} />
      </div>
    </fieldset>
  );
}

/** A group's rows and its "Add condition" menu. A type already in this group is disabled. */
function GroupConditions({
  idPrefix,
  groupIndex,
  types,
  header = null,
  extraAction = null,
}: {
  idPrefix: string;
  groupIndex: number;
  types: readonly CampaignConditionType[];
  header?: ReactNode;
  extraAction?: ReactNode;
}) {
  const { control } = useFormContext<ConditionsFormValues>();
  const { fields, append, remove } = useFieldArray({ control, name: `conditions.groups.${groupIndex}.conditions` });
  const usedTypes = new Set<string>(fields.map((field) => field.type));

  return (
    <>
      {header}
      {fields.length > 0 && (
        <div className="flex flex-col gap-3">
          {fields.map((field, index) => (
            <ConditionRow
              key={field.id}
              path={conditionPath(groupIndex, index)}
              type={field.type}
              onRemove={() => remove(index)}
            />
          ))}
        </div>
      )}
      <div className="flex flex-wrap items-center gap-2">
        <AddConditionMenu
          id={addConditionId(idPrefix, groupIndex)}
          types={types}
          usedTypes={usedTypes}
          onAdd={(type) => append(newCondition(type))}
        />
        {extraAction}
      </div>
    </>
  );
}

function AddConditionMenu({
  id,
  types,
  usedTypes,
  onAdd,
}: {
  id: string;
  types: readonly CampaignConditionType[];
  usedTypes: Set<string>;
  onAdd: (type: CampaignConditionType) => void;
}) {
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button id={id} type="button" variant="outline" size="sm">
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

function MatchSelect({
  name,
  label,
}: {
  name: 'conditions.match' | `conditions.groups.${number}.match`;
  label: string;
}) {
  const { control } = useFormContext<ConditionsFormValues>();
  const { field } = useController({ control, name });

  return (
    <Select value={field.value} onValueChange={field.onChange}>
      <SelectTrigger size="sm" className="w-24" aria-label={label}>
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

function addConditionId(idPrefix: string, groupIndex: number): string {
  return `${idPrefix}-group-${groupIndex}-add-condition`;
}
