import { useState } from 'react';
import { closestCenter, DndContext, PointerSensor, useSensor, useSensors, type DragEndEvent } from '@dnd-kit/core';
import { restrictToVerticalAxis } from '@dnd-kit/modifiers';
import { arrayMove, SortableContext, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { GripVertical, Lock } from 'lucide-react';
import { useNavigate } from 'react-router';
import { toast } from 'sonner';
import { __, _n, sprintf } from '@wordpress/i18n';

import { toastApiError } from '@/lib/api/api';
import {
  useReorderRewardsMutation,
  useRewardsQuery,
  useUpdateRewardStatusMutation,
} from '@/lib/queries/reward.queries';
import { resolveRewardDisplayStatus, Reward } from '@/lib/schema/reward.schema';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { ErrorState } from '@/components/ui/custom/error-state';
import { YayLoyaltyToolTip } from '@/components/ui/custom/tooltip';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Skeleton } from '@/components/ui/skeleton';
import { Switch } from '@/components/ui/switch';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import EllipsisIcon from '@/components/icons/EllipsisIcon';
import { CampaignStatusBadge } from '@/pro/campaigns/CampaignStatusBadge';
import { RewardArchiveDialog } from './archive/RewardArchiveDialog';
import {
  availabilityLabel,
  costLabel,
  headerSubtitle,
  isCreatableRewardType,
  resolveRewardsScreen,
  RewardsScreen,
  rewardSubtitle,
  typeLabel,
} from './reward-labels';

/**
 * Pro's Rewards screen: the launcher catalog in display order, plus an
 * Archived view reached from the header. Rows open the reward editor, their
 * switch pauses or resumes, and the ⋯ menu duplicates, archives, restores
 * or deletes.
 */
export function RewardTable() {
  const navigate = useNavigate();

  const { data, isPending, isError, refetch } = useRewardsQuery({ perPage: 100 });
  const ready = !isPending && !isError;

  const reorderMutation = useReorderRewardsMutation();

  // Shows a drop at once while its save is in flight. Ignored once the query's own data changes,
  // so every refetch replaces it with the stored order.
  const [override, setOverride] = useState<{ source: typeof data; rows: Reward[] }>();

  const screen = resolveRewardsScreen(override && override.source === data ? override.rows : (data?.data ?? []));

  const [view, setView] = useState<'catalog' | 'archived'>('catalog');
  // Falls back on its own once the last archived row is restored or deleted.
  const showArchived = view === 'archived' && screen.archivedRows.length > 0;
  // The row the dialog acts on; a snapshot, so a refetch while it is open cannot change it.
  const [archiveTarget, setArchiveTarget] = useState<Reward>();

  const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 4 } }));

  function onDragEnd(event: DragEndEvent) {
    const { active, over } = event;

    if (!over || active.id === over.id) {
      return;
    }

    const fromIndex = screen.rows.findIndex((row) => row.id === active.id);
    const toIndex = screen.rows.findIndex((row) => row.id === over.id);

    // The built-in is pinned first by resolveRewardsScreen(); nothing may move into its slot.
    if (fromIndex === -1 || toIndex === -1 || screen.rows[toIndex].is_builtin) {
      return;
    }

    // Moved within screen.rows, the list the indexes came from; the raw list still holds archived rows.
    const nextRows = arrayMove(screen.rows, fromIndex, toIndex);
    setOverride({ source: data, rows: nextRows });
    reorderMutation.mutate(
      nextRows.map((row) => row.id),
      {
        onError: (error) => toastApiError(error, __('Could not save the new order.', 'yayloyalty')),
      },
    );
  }

  return (
    <div className="flex flex-col gap-4">
      <Card>
        <CardContent className="flex flex-col gap-4">
          <div className="flex flex-wrap items-center justify-between gap-3">
            {isPending ? (
              <Skeleton className="h-4 w-64" />
            ) : (
              <RewardTableSubtitle screen={screen} showArchived={showArchived} onViewChange={setView} />
            )}
            <div className="flex items-center gap-3">
              <Button onClick={() => navigate('/rewards/new')}>{__('Add reward', 'yayloyalty')}</Button>
            </div>
          </div>

          {isError && <ErrorState message={__('Could not load rewards.', 'yayloyalty')} onRetry={() => refetch()} />}

          {isPending && <RewardTableSkeleton />}

          {ready && (
            <div className="overflow-hidden rounded-lg border">
              <div className="overflow-x-auto">
                {/* Outside <Table>: DndContext renders hidden screen-reader <div>s, invalid inside <tbody>. */}
                <DndContext
                  sensors={sensors}
                  collisionDetection={closestCenter}
                  modifiers={[restrictToVerticalAxis]}
                  onDragEnd={onDragEnd}
                >
                  <Table>
                    <RewardTableHeader />
                    <TableBody>
                      {showArchived ? (
                        screen.archivedRows.map((row) => (
                          <RewardRow key={row.id} reward={row} onArchiveOrDelete={setArchiveTarget} />
                        ))
                      ) : (
                        <SortableContext
                          items={screen.rows.map((row) => row.id)}
                          strategy={verticalListSortingStrategy}
                        >
                          {screen.rows.map((row) => (
                            <RewardRow key={row.id} reward={row} onArchiveOrDelete={setArchiveTarget} />
                          ))}
                        </SortableContext>
                      )}
                    </TableBody>
                  </Table>
                </DndContext>
              </div>
            </div>
          )}
        </CardContent>
      </Card>

      {archiveTarget && (
        <RewardArchiveDialog
          reward={archiveTarget}
          onClose={() => setArchiveTarget(undefined)}
          onViewArchived={() => setView('archived')}
        />
      )}
    </div>
  );
}

/** The line above the table: catalog count with an "{n} archived" link, or the Archived view's way back. */
function RewardTableSubtitle({
  screen,
  showArchived,
  onViewChange,
}: {
  screen: RewardsScreen;
  showArchived: boolean;
  onViewChange: (view: 'catalog' | 'archived') => void;
}) {
  const archivedCount = screen.archivedRows.length;

  if (showArchived) {
    return (
      <p className="text-foreground flex-1 text-sm">
        {sprintf(
          /* translators: %d: number of archived rewards. */
          _n('%d archived reward.', '%d archived rewards.', archivedCount, 'yayloyalty'),
          archivedCount,
        )}{' '}
        <button type="button" className="text-foreground underline" onClick={() => onViewChange('catalog')}>
          {__('Back to rewards', 'yayloyalty')}
        </button>
      </p>
    );
  }

  const { count, detail } = headerSubtitle(screen);

  return (
    <p className="text-foreground flex-1 text-sm">
      {count}
      {archivedCount > 0 && (
        <>
          {' - '}
          <button type="button" className="text-foreground hover:underline" onClick={() => onViewChange('archived')}>
            {sprintf(
              /* translators: %d: number of archived rewards. */
              __('%d archived', 'yayloyalty'),
              archivedCount,
            )}
          </button>
        </>
      )}
      {`. ${detail}`}
    </p>
  );
}

/** Shared between the real table and its loading skeleton, so column widths never drift between the two. */
function RewardTableHeader() {
  return (
    <TableHeader>
      <TableRow>
        <TableHead className="w-6" />
        <TableHead>{__('Reward', 'yayloyalty')}</TableHead>
        <TableHead>{__('Type', 'yayloyalty')}</TableHead>
        <TableHead>{__('Costs', 'yayloyalty')}</TableHead>
        <TableHead>{__('Availability', 'yayloyalty')}</TableHead>
        <TableHead className="text-right">{__('Redeemed', 'yayloyalty')}</TableHead>
        <TableHead>{__('Status', 'yayloyalty')}</TableHead>
        <TableHead className="w-1" />
      </TableRow>
    </TableHeader>
  );
}

/**
 * One reward row, sortable via dnd-kit; RewardTable saves a drop as the new
 * display order. The built-in row is locked first; an archived row is never
 * dragged. The name opens the editor and the switch writes active/paused.
 */
function RewardRow({
  reward,
  onArchiveOrDelete,
}: {
  reward: Reward;
  /** Opens RewardArchiveDialog, which picks archive or delete from is_deletable. */
  onArchiveOrDelete: (reward: Reward) => void;
}) {
  const navigate = useNavigate();
  const statusMutation = useUpdateRewardStatusMutation(reward.id);
  const isArchived = reward.status === 'archived';
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: reward.id,
    disabled: reward.is_builtin || isArchived,
  });
  const displayStatus = resolveRewardDisplayStatus(reward.status, reward.starts_at, reward.ends_at);

  async function toggle(isOn: boolean) {
    try {
      await statusMutation.mutateAsync(isOn ? 'active' : 'paused');
    } catch (error) {
      await toastApiError(error, __('Could not change the reward’s status.', 'yayloyalty'));
    }
  }

  // Back as paused, never active: going live again is the merchant's own switch.
  async function restore() {
    try {
      await statusMutation.mutateAsync('paused');
      toast.success(__('Reward restored as paused.', 'yayloyalty'));
    } catch (error) {
      await toastApiError(error, __('Could not restore the reward.', 'yayloyalty'));
    }
  }

  return (
    <TableRow
      ref={setNodeRef}
      style={{ transform: CSS.Transform.toString(transform), transition }}
      data-state={isDragging ? 'selected' : undefined}
    >
      <TableCell className="px-1">
        <DragHandle reward={reward} isArchived={isArchived} attributes={attributes} listeners={listeners} />
      </TableCell>
      <TableCell>
        <div className="flex flex-col gap-0.5">
          <div className="flex items-center gap-1.5">
            <Button
              variant="link"
              size="sm"
              className="h-auto p-0 font-semibold"
              onClick={() => navigate(`/rewards/${reward.id}/edit`)}
            >
              {reward.name}
            </Button>
            {reward.is_builtin && (
              <YayLoyaltyToolTip
                trigger={<Lock className="text-muted-foreground size-3" />}
                content={__('The built-in reward cannot be archived.', 'yayloyalty')}
              />
            )}
          </div>
          <span className="text-muted-foreground text-xs">{rewardSubtitle(reward)}</span>
        </div>
      </TableCell>
      <TableCell>
        <Badge variant="outline">{typeLabel(reward.type)}</Badge>
      </TableCell>
      <TableCell className="text-sm font-semibold tabular-nums">{costLabel(reward)}</TableCell>
      <TableCell className="text-muted-foreground text-xs">{availabilityLabel(reward)}</TableCell>
      {/* TODO(redemption): em dash until redemption defines "redeemed"; the count then reads reward.redemption_summary, never a second query. */}
      <TableCell className="text-muted-foreground text-right text-xs">—</TableCell>
      <TableCell>
        {displayStatus === 'active' || displayStatus === 'paused' ? (
          <div className="flex items-center gap-2">
            <Switch
              size="sm"
              checked={displayStatus === 'active'}
              disabled={statusMutation.isPending}
              onCheckedChange={toggle}
            />
            <span className="text-muted-foreground text-xs">
              {displayStatus === 'active' ? __('Active', 'yayloyalty') : __('Paused', 'yayloyalty')}
            </span>
          </div>
        ) : (
          <CampaignStatusBadge status={displayStatus} />
        )}
      </TableCell>
      <TableCell className="text-right">
        {/* The built-in can be neither duplicated (its type is never created) nor archived, so it has no menu. */}
        {!reward.is_builtin && (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <button
                type="button"
                className="text-muted-foreground mx-auto flex size-6 items-center justify-center"
                aria-label={sprintf(
                  /* translators: %s: the reward's name. */
                  __('“%s” actions', 'yayloyalty'),
                  reward.name,
                )}
              >
                <EllipsisIcon />
              </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              {isArchived ? (
                <>
                  <DropdownMenuItem disabled={statusMutation.isPending} onSelect={restore}>
                    {__('Restore', 'yayloyalty')}
                  </DropdownMenuItem>
                  {reward.is_deletable && (
                    <DropdownMenuItem variant="destructive" onSelect={() => onArchiveOrDelete(reward)}>
                      {__('Delete permanently', 'yayloyalty')}
                    </DropdownMenuItem>
                  )}
                </>
              ) : (
                <>
                  {/* Create mode, seeded from this reward; nothing is written until Save. */}
                  {isCreatableRewardType(reward.type) && (
                    <DropdownMenuItem onSelect={() => navigate(`/rewards/new/${reward.type}?from=${reward.id}`)}>
                      {__('Duplicate', 'yayloyalty')}
                    </DropdownMenuItem>
                  )}
                  <DropdownMenuItem variant="destructive" onSelect={() => onArchiveOrDelete(reward)}>
                    {__('Archive', 'yayloyalty')}
                  </DropdownMenuItem>
                </>
              )}
            </DropdownMenuContent>
          </DropdownMenu>
        )}
      </TableCell>
    </TableRow>
  );
}

/** Nothing for an archived row, a locked grip for the built-in, a live grip otherwise. */
function DragHandle({
  reward,
  isArchived,
  attributes,
  listeners,
}: {
  reward: Reward;
  isArchived: boolean;
  attributes: ReturnType<typeof useSortable>['attributes'];
  listeners: ReturnType<typeof useSortable>['listeners'];
}) {
  if (isArchived) {
    return null;
  }

  if (reward.is_builtin) {
    return (
      <YayLoyaltyToolTip
        trigger={<GripVertical className="text-muted-foreground/40 size-4 cursor-not-allowed" />}
        content={__('The default reward always comes first.', 'yayloyalty')}
      />
    );
  }

  return (
    <button
      type="button"
      className="text-muted-foreground flex cursor-grab touch-none items-center active:cursor-grabbing"
      aria-label={__('Drag to reorder', 'yayloyalty')}
      {...attributes}
      {...listeners}
    >
      <GripVertical className="size-4" />
    </button>
  );
}

function RewardTableSkeleton() {
  return (
    <div className="overflow-hidden rounded-lg border">
      <div className="overflow-x-auto">
        <Table>
          <RewardTableHeader />
          <TableBody>
            {[0, 1, 2].map((key) => (
              <TableRow key={key} className="hover:bg-transparent">
                <TableCell className="px-1">
                  <GripVertical className="text-muted-foreground/40 size-4" />
                </TableCell>
                <TableCell>
                  <div className="flex flex-col gap-1.5">
                    <Skeleton className="h-4 w-32" />
                    <Skeleton className="h-3 w-48" />
                  </div>
                </TableCell>
                <TableCell>
                  <Skeleton className="h-5 w-20 rounded-full" />
                </TableCell>
                <TableCell>
                  <Skeleton className="h-4 w-12" />
                </TableCell>
                <TableCell>
                  <Skeleton className="h-3 w-28" />
                </TableCell>
                <TableCell>
                  <Skeleton className="ml-auto h-3 w-6" />
                </TableCell>
                <TableCell>
                  <Skeleton className="h-5 w-14 rounded-full" />
                </TableCell>
                <TableCell>
                  <Skeleton className="mx-auto size-6" />
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
    </div>
  );
}
