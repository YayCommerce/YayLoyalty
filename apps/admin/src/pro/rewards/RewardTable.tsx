import { useEffect, useState } from 'react';
import { closestCenter, DndContext, PointerSensor, useSensor, useSensors, type DragEndEvent } from '@dnd-kit/core';
import { restrictToVerticalAxis } from '@dnd-kit/modifiers';
import { arrayMove, SortableContext, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { GripVertical, InfoIcon, Lock } from 'lucide-react';
import { useNavigate } from 'react-router';
import { __, sprintf } from '@wordpress/i18n';

import { toastApiError } from '@/lib/api/api';
import {
  useReorderRewardsMutation,
  useRewardsQuery,
  useUpdateRewardStatusMutation,
} from '@/lib/queries/reward.queries';
import { resolveRewardDisplayStatus, Reward } from '@/lib/schema/reward.schema';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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
import {
  availabilityLabel,
  costLabel,
  headerSubtitle,
  resolveRewardsScreen,
  rewardSubtitle,
  typeLabel,
} from './reward-labels';
import { RewardCatalogEmptyState, RewardCatalogTemplateCards } from './RewardCatalogEmptyState';

/**
 * Pro's Rewards screen: the launcher catalog in display order. Rows open the
 * reward editor and their switch pauses or resumes; delete, duplicate and
 * redeem stay UI-only until the redemption engine exists.
 */
export function RewardTable() {
  const navigate = useNavigate();

  const { data, isPending, isError } = useRewardsQuery({ perPage: 100 });
  const isReady = !isPending && !isError;

  const reorderMutation = useReorderRewardsMutation();

  // Shows a drop at once while its save is in flight; every refetch replaces it with the stored order.
  const [orderedRows, setOrderedRows] = useState<Reward[]>([]);

  useEffect(() => {
    setOrderedRows(data?.data ?? []);
  }, [data]);

  const screen = resolveRewardsScreen(orderedRows);

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
    setOrderedRows(nextRows);
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
              <p className="text-foreground flex-1 text-sm">{headerSubtitle(screen)}</p>
            )}
            <div className="flex items-center gap-3">
              <Button variant="outline">{__('Redemptions log', 'yayloyalty')}</Button>
              <Button onClick={() => navigate('/rewards/new')}>{__('Add reward', 'yayloyalty')}</Button>
            </div>
          </div>

          {isReady && (
            <Alert variant="outline" className={screen.isInitCase ? 'gap-y-2' : undefined}>
              <InfoIcon />
              <AlertDescription
                className={screen.isInitCase ? 'flex-row items-center justify-between gap-3' : undefined}
              >
                {screen.isInitCase ? (
                  <>
                    <span>
                      {__(
                        'The slider is live at checkout. Customers need 100 points before they can use it, and the first redemption will fill the log.',
                        'yayloyalty',
                      )}
                    </span>
                    <Button
                      variant="primary-outline-fill"
                      size="sm"
                      className="shrink-0"
                      onClick={() => navigate('/campaigns')}
                    >
                      {__('Check the earning rate', 'yayloyalty')}
                    </Button>
                  </>
                ) : (
                  __(
                    'The customer picks a reward, so there is no priority here — order is display order, and availability decides who can see each one.',
                    'yayloyalty',
                  )
                )}
              </AlertDescription>
            </Alert>
          )}

          {isError && <p className="text-destructive text-sm">{__('Could not load rewards.', 'yayloyalty')}</p>}

          {isPending && <RewardTableSkeleton />}

          {isReady && (
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
                      <SortableContext items={screen.rows.map((row) => row.id)} strategy={verticalListSortingStrategy}>
                        {screen.rows.map((row) => (
                          <RewardRow key={row.id} reward={row} />
                        ))}
                      </SortableContext>
                    </TableBody>
                  </Table>
                </DndContext>
              </div>
            </div>
          )}

          {isReady && screen.isInitCase && (
            <>
              <RewardCatalogEmptyState />
              <RewardCatalogTemplateCards />
            </>
          )}
        </CardContent>
      </Card>

      {isReady && !screen.isInitCase && <RewardInfoCards />}
    </div>
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
 * display order. The built-in row is locked first. The name opens the editor
 * and the switch writes active/paused; the ⋯ menu is still UI-only.
 */
function RewardRow({ reward }: { reward: Reward }) {
  const navigate = useNavigate();
  const statusMutation = useUpdateRewardStatusMutation(reward.id);
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: reward.id,
    disabled: reward.is_builtin,
  });
  const displayStatus = resolveRewardDisplayStatus(reward.status, reward.starts_at, reward.ends_at);

  async function toggle(isOn: boolean) {
    try {
      await statusMutation.mutateAsync(isOn ? 'active' : 'paused');
    } catch (error) {
      await toastApiError(error, __('Could not change the reward’s status.', 'yayloyalty'));
    }
  }

  return (
    <TableRow
      ref={setNodeRef}
      style={{ transform: CSS.Transform.toString(transform), transition }}
      data-state={isDragging ? 'selected' : undefined}
    >
      <TableCell className="px-1">
        {reward.is_builtin ? (
          <YayLoyaltyToolTip
            trigger={<GripVertical className="text-muted-foreground/40 size-4 cursor-not-allowed" />}
            content={__('The default reward always comes first.', 'yayloyalty')}
          />
        ) : (
          <button
            type="button"
            className="text-muted-foreground flex cursor-grab touch-none items-center active:cursor-grabbing"
            aria-label={__('Drag to reorder', 'yayloyalty')}
            {...attributes}
            {...listeners}
          >
            <GripVertical className="size-4" />
          </button>
        )}
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
              <>
                <Badge variant="outline" className="uppercase">
                  {__('Default', 'yayloyalty')}
                </Badge>
                <YayLoyaltyToolTip
                  trigger={<Lock className="text-muted-foreground size-3" />}
                  content={__('The built-in reward cannot be archived.', 'yayloyalty')}
                />
              </>
            )}
          </div>
          <span className="text-muted-foreground text-xs">{rewardSubtitle(reward)}</span>
        </div>
      </TableCell>
      <TableCell>
        <Badge variant="outline" className="uppercase">
          {typeLabel(reward.type)}
        </Badge>
      </TableCell>
      <TableCell className="text-sm font-semibold tabular-nums">{costLabel(reward)}</TableCell>
      <TableCell className="text-muted-foreground text-xs">{availabilityLabel(reward)}</TableCell>
      {/* Em dash, not "0": no redemptions table exists yet to count from (docs/data-model/redemptions.md). */}
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
              {displayStatus === 'active' ? __('On', 'yayloyalty') : __('Paused', 'yayloyalty')}
            </span>
          </div>
        ) : (
          <CampaignStatusBadge status={displayStatus} />
        )}
      </TableCell>
      <TableCell className="text-right">
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
            <DropdownMenuItem onSelect={() => {}}>{__('Duplicate', 'yayloyalty')}</DropdownMenuItem>
            {!reward.is_builtin && (
              <DropdownMenuItem variant="destructive" onSelect={() => {}}>
                {__('Archive', 'yayloyalty')}
              </DropdownMenuItem>
            )}
          </DropdownMenuContent>
        </DropdownMenu>
      </TableCell>
    </TableRow>
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

/** The two footer cards shown once there is a real catalog to explain; the screen carries no equivalent for the empty case. */
function RewardInfoCards() {
  return (
    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
      <Card>
        <CardContent className="flex flex-col gap-1">
          <p className="text-sm font-medium">{__('Two ways points leave a balance', 'yayloyalty')}</p>
          <p className="text-muted-foreground text-sm">
            {__(
              'The slider spends points at checkout, with no coupon involved. Catalog rewards spend points at claim time and issue a single-use coupon the customer applies to a later order.',
              'yayloyalty',
            )}
          </p>
        </CardContent>
      </Card>
      <Card>
        <CardContent className="flex flex-col gap-1">
          <p className="text-sm font-medium">{__('Order is what the customer sees', 'yayloyalty')}</p>
          <p className="text-muted-foreground text-sm">
            {__(
              'The launcher and My Account list rewards in this order, with the ones a customer cannot afford or is not eligible for shown last and marked. Paused rewards are hidden entirely.',
              'yayloyalty',
            )}
          </p>
        </CardContent>
      </Card>
    </div>
  );
}
