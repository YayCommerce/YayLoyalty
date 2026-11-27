import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { GripVertical, Lock, Package, Percent, Tag, Truck, type LucideIcon } from 'lucide-react';
import { useNavigate } from 'react-router';
import { toast } from 'sonner';
import { __, sprintf } from '@wordpress/i18n';

import { toastApiError } from '@/lib/api/api';
import { useUpdateRewardStatusMutation } from '@/lib/queries/reward.queries';
import { isCatalogRewardType, resolveRewardDisplayStatus, Reward } from '@/lib/schema/reward.schema';
import { Badge } from '@/components/ui/badge';
import { YayLoyaltyToolTip } from '@/components/ui/custom/tooltip';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { TableCell, TableRow } from '@/components/ui/table';
import EllipsisIcon from '@/components/icons/EllipsisIcon';
import { CampaignStatusBadge } from '@/pro/campaigns/CampaignStatusBadge';
import { availabilityLabel, costLabel, isCreatableRewardType, templateTitle, typeLabel } from './reward-labels';

const TYPE_ICONS: Partial<Record<Reward['type'], LucideIcon>> = {
  fixed_coupon: Tag,
  percent_coupon: Percent,
  free_shipping: Truck,
  free_product: Package,
};

/**
 * One reward row, sortable via dnd-kit; RewardTable saves a drop as the new display order. The built-in row is
 * locked first; an archived row, or any row while the list is searched, is never dragged. The name opens the
 * editor modal and the ⋯ menu pauses, resumes, duplicates, archives, restores or deletes.
 */
export function RewardTableRow({
  reward,
  dragDisabled,
  onArchiveOrDelete,
}: {
  reward: Reward;
  dragDisabled: boolean;
  /** Opens RewardArchiveDialog, which picks archive or delete from is_deletable. */
  onArchiveOrDelete: (reward: Reward) => void;
}) {
  const navigate = useNavigate();
  const statusMutation = useUpdateRewardStatusMutation(reward.id);
  const isArchived = reward.status === 'archived';
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: reward.id,
    disabled: reward.is_builtin || isArchived || dragDisabled,
  });
  const displayStatus = resolveRewardDisplayStatus(reward.status, reward.starts_at, reward.ends_at);
  const TypeIcon = reward.is_builtin ? Lock : TYPE_ICONS[reward.type];

  async function setStatus(next: 'active' | 'paused', failure: string, success?: string) {
    try {
      await statusMutation.mutateAsync(next);
      if (success) toast.success(success);
    } catch (error) {
      await toastApiError(error, failure);
    }
  }

  const statusFailure = __('Could not change the reward’s status.', 'yayloyalty');

  return (
    <TableRow
      ref={setNodeRef}
      style={{ transform: CSS.Transform.toString(transform), transition }}
      data-state={isDragging ? 'selected' : undefined}
    >
      <TableCell className="w-8 px-1">
        {!isArchived && !dragDisabled && (
          <DragHandle isBuiltin={reward.is_builtin} attributes={attributes} listeners={listeners} />
        )}
      </TableCell>
      <TableCell>
        <button
          type="button"
          className="text-foreground cursor-pointer text-sm font-semibold hover:underline"
          onClick={() => navigate(`/rewards/${reward.id}/edit`)}
        >
          {reward.name}
        </button>
      </TableCell>
      <TableCell className="text-center">
        <Badge variant="outline" className="gap-1 font-normal">
          {TypeIcon && <TypeIcon className="size-3" />}
          {isCatalogRewardType(reward.type) ? templateTitle(reward.type) : typeLabel(reward.type)}
        </Badge>
      </TableCell>
      <TableCell className="text-center tabular-nums">{costLabel(reward)}</TableCell>
      <TableCell>{availabilityLabel(reward)}</TableCell>
      {/* TODO(redemption): em dash until redemption defines "redeemed"; the count then reads reward.redemption_summary, never a second query. */}
      <TableCell className="text-muted-foreground text-center">—</TableCell>
      <TableCell>
        <CampaignStatusBadge status={displayStatus} />
      </TableCell>
      <TableCell className="text-right">
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <button
              type="button"
              className="text-muted-foreground mx-auto flex size-6 cursor-pointer items-center justify-center"
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
                {/* Back as paused, never active: going live again is the merchant's own choice. */}
                <DropdownMenuItem
                  disabled={statusMutation.isPending}
                  onSelect={() =>
                    setStatus(
                      'paused',
                      __('Could not restore the reward.', 'yayloyalty'),
                      __('Reward restored as paused.', 'yayloyalty'),
                    )
                  }
                >
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
                {displayStatus === 'active' && (
                  <DropdownMenuItem
                    disabled={statusMutation.isPending}
                    onSelect={() => setStatus('paused', statusFailure)}
                  >
                    {__('Pause', 'yayloyalty')}
                  </DropdownMenuItem>
                )}
                {displayStatus === 'paused' && (
                  <DropdownMenuItem
                    disabled={statusMutation.isPending}
                    onSelect={() => setStatus('active', statusFailure)}
                  >
                    {__('Resume', 'yayloyalty')}
                  </DropdownMenuItem>
                )}
                {/* Create mode, seeded from this reward; nothing is written until Save. */}
                {isCreatableRewardType(reward.type) && (
                  <DropdownMenuItem onSelect={() => navigate(`/rewards/new/${reward.type}?from=${reward.id}`)}>
                    {__('Duplicate', 'yayloyalty')}
                  </DropdownMenuItem>
                )}
                {/* The built-in can never be archived. */}
                {!reward.is_builtin && (
                  <DropdownMenuItem variant="destructive" onSelect={() => onArchiveOrDelete(reward)}>
                    {__('Archive', 'yayloyalty')}
                  </DropdownMenuItem>
                )}
              </>
            )}
          </DropdownMenuContent>
        </DropdownMenu>
      </TableCell>
    </TableRow>
  );
}

/** A locked grip for the built-in, a live grip otherwise. */
function DragHandle({
  isBuiltin,
  attributes,
  listeners,
}: {
  isBuiltin: boolean;
  attributes: ReturnType<typeof useSortable>['attributes'];
  listeners: ReturnType<typeof useSortable>['listeners'];
}) {
  if (isBuiltin) {
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
