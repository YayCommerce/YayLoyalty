import { useState } from 'react';
import { EllipsisVertical, Search } from 'lucide-react';
import { toast } from 'sonner';
import { __, sprintf } from '@wordpress/i18n';

import { toastApiError } from '@/lib/api/api';
import { formatStoreDecimal } from '@/lib/helpers/format.helper';
import { formatCount, formatPoints } from '@/lib/helpers/points.helper';
import { Tier, TIER_LIMITS, TierInUse } from '@/lib/schema/tier.schema';
import { Badge } from '@/components/ui/badge';
import { Button, LoadingButton } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/custom/dialog';
import { ErrorState } from '@/components/ui/custom/error-state';
import { YayLoyaltyToolTip } from '@/components/ui/custom/tooltip';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { InputGroup, InputGroupAddon, InputGroupInput } from '@/components/ui/input-group';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { TierFormDialog, TierFormTarget } from './TierFormDialog';
import { TierIconChip } from './TierIconChip';
import { readTierInUse } from './tiers.api';
import { useDeleteTierMutation, useTiersEnabled, useTiersQuery } from './tiers.queries';

/** Mirrors TiersRestController::create_item()'s limit message: the tooltip shows before any request. */
const tierLimitMessage = () =>
  sprintf(
    /* translators: %s: the most tiers a store may have. */
    __('You can have at most %s tiers.', 'yayloyalty'),
    formatCount(TIER_LIMITS.maxLive),
  );

/**
 * Pro's Tiers screen: the live tiers in threshold order, with the create, edit and delete dialogs.
 * Rank follows threshold, so there is no drag; changing a threshold moves the row.
 */
export function TierCatalog() {
  const { data: tiers, isPending, isError, refetch } = useTiersQuery();
  const [formTarget, setFormTarget] = useState<TierFormTarget>();
  const [deleteTarget, setDeleteTarget] = useState<Tier>();
  const [search, setSearch] = useState('');
  // While tiers are off the stored counts are frozen, so they would read as live.
  const showMembers = useTiersEnabled();

  const atLimit = (tiers?.length ?? 0) >= TIER_LIMITS.maxLive;
  const query = search.trim().toLowerCase();
  const shown = tiers?.filter((tier) => tier.name.toLowerCase().includes(query)) ?? [];
  const addButton = (
    <Button size="sm" disabled={isPending || isError || atLimit} onClick={() => setFormTarget({ mode: 'create' })}>
      {__('Add new tier', 'yayloyalty')}
    </Button>
  );

  return (
    <Card className="gap-4 p-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="m-0 text-base font-semibold">{__('Tier multipliers', 'yayloyalty')}</h2>
        <div className="flex flex-wrap items-center gap-2">
          <InputGroup size="small" className="w-44">
            <InputGroupAddon>
              <Search className="size-4" />
            </InputGroupAddon>
            <InputGroupInput
              placeholder={__('Search', 'yayloyalty')}
              aria-label={__('Search tiers', 'yayloyalty')}
              value={search}
              onChange={(event) => setSearch(event.target.value)}
            />
          </InputGroup>
          {atLimit ? (
            <YayLoyaltyToolTip
              content={tierLimitMessage()}
              trigger={<span className="inline-flex">{addButton}</span>}
            />
          ) : (
            addButton
          )}
        </div>
      </div>

      {isError && <ErrorState message={__('Could not load tiers.', 'yayloyalty')} onRetry={() => refetch()} />}

      {!isError && (
        <div className="overflow-hidden rounded-lg border">
          <div className="overflow-x-auto">
            <Table>
              <TableHeader className="bg-muted/50">
                <TableRow>
                  <TableHead>{__('Tier', 'yayloyalty')}</TableHead>
                  <TableHead className="text-center">{__('Points to reach', 'yayloyalty')}</TableHead>
                  <TableHead className="text-center">{__('Multiplier', 'yayloyalty')}</TableHead>
                  <TableHead className="text-center">{__('Entry bonus', 'yayloyalty')}</TableHead>
                  <TableHead className="text-center">{__('Members', 'yayloyalty')}</TableHead>
                  <TableHead className="w-12" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {isPending ? (
                  [0, 1, 2].map((key) => <TierRowSkeleton key={key} />)
                ) : shown.length === 0 ? (
                  <TableRow className="hover:bg-transparent">
                    <TableCell colSpan={6} className="text-muted-foreground py-8 text-center">
                      {query ? __('No tiers match your search.', 'yayloyalty') : __('No tiers yet.', 'yayloyalty')}
                    </TableCell>
                  </TableRow>
                ) : (
                  shown.map((tier) => (
                    <TierRow
                      key={tier.id}
                      tier={tier}
                      showMembers={showMembers}
                      onEdit={() => setFormTarget({ mode: 'edit', tier })}
                      onDelete={() => setDeleteTarget(tier)}
                    />
                  ))
                )}
              </TableBody>
            </Table>
          </div>
        </div>
      )}

      {formTarget && tiers && (
        <TierFormDialog target={formTarget} tiers={tiers} onClose={() => setFormTarget(undefined)} />
      )}

      {deleteTarget && <TierDeleteDialog tier={deleteTarget} onClose={() => setDeleteTarget(undefined)} />}
    </Card>
  );
}

function TierRow({
  tier,
  showMembers,
  onEdit,
  onDelete,
}: {
  tier: Tier;
  showMembers: boolean;
  onEdit: () => void;
  onDelete: () => void;
}) {
  return (
    <TableRow>
      <TableCell>
        <button type="button" className="flex items-center gap-3 font-medium hover:underline" onClick={onEdit}>
          <TierIconChip color={tier.color} />
          {tier.name}
        </button>
      </TableCell>
      <TableCell className="text-center tabular-nums">{formatPoints(tier.threshold)}</TableCell>
      <TableCell className="text-center">
        <Badge variant="secondary" className="tabular-nums">
          {sprintf(
            /* translators: %s: the tier's points multiplier, such as 1.25. */
            __('×%s', 'yayloyalty'),
            formatStoreDecimal(Number(tier.multiplier), 2),
          )}
        </Badge>
      </TableCell>
      <TableCell className="text-center tabular-nums">
        {tier.entry_bonus > 0
          ? sprintf(
              /* translators: %s: the tier's entry bonus, in points. */
              __('+%s pts', 'yayloyalty'),
              formatPoints(tier.entry_bonus),
            )
          : __('0 pts', 'yayloyalty')}
      </TableCell>
      <TableCell className="text-center tabular-nums">{showMembers ? formatCount(tier.member_count) : '—'}</TableCell>
      <TableCell>
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <button
              type="button"
              className="text-muted-foreground mx-auto flex size-6 items-center justify-center"
              aria-label={sprintf(
                /* translators: %s: the tier's name. */
                __('“%s” actions', 'yayloyalty'),
                tier.name,
              )}
            >
              <EllipsisVertical className="size-4" />
            </button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuItem onSelect={onEdit}>{__('Edit', 'yayloyalty')}</DropdownMenuItem>
            <DropdownMenuItem variant="destructive" onSelect={onDelete}>
              {__('Delete', 'yayloyalty')}
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </TableCell>
    </TableRow>
  );
}

function TierRowSkeleton() {
  return (
    <TableRow className="hover:bg-transparent">
      <TableCell>
        <Skeleton className="h-4 w-28" />
      </TableCell>
      {[0, 1, 2, 3].map((key) => (
        <TableCell key={key}>
          <Skeleton className="mx-auto h-4 w-12" />
        </TableCell>
      ))}
      <TableCell />
    </TableRow>
  );
}

/** Confirms a delete. When rewards still require the tier, it lists them instead and offers no delete. */
function TierDeleteDialog({ tier, onClose }: { tier: Tier; onClose: () => void }) {
  const deleteTier = useDeleteTierMutation();
  const [blockers, setBlockers] = useState<TierInUse>();

  async function confirm() {
    try {
      await deleteTier.mutateAsync(tier.id);
      toast.success(
        sprintf(
          /* translators: %s: the tier's name. */
          __('“%s” deleted.', 'yayloyalty'),
          tier.name,
        ),
      );
      onClose();
    } catch (error) {
      const inUse = await readTierInUse(error);

      if (inUse) {
        setBlockers(inUse);
      } else {
        await toastApiError(error, __('Could not delete the tier.', 'yayloyalty'));
      }
    }
  }

  return (
    <Dialog open onOpenChange={(open) => !open && !deleteTier.isPending && onClose()}>
      <DialogContent showCloseButton={!deleteTier.isPending}>
        <DialogHeader>
          <DialogTitle>
            {sprintf(
              /* translators: %s: the tier's name. */
              __('Delete “%s”?', 'yayloyalty'),
              tier.name,
            )}
          </DialogTitle>
          <DialogDescription>
            {blockers
              ? blockers.message
              : __('The tier leaves the catalogue. Its history stays in each customer’s activity.', 'yayloyalty')}
          </DialogDescription>
        </DialogHeader>

        {blockers && (
          <ul className="list-disc pl-5 text-sm">
            {blockers.rewards.map((reward) => (
              <li key={reward.id}>{reward.name}</li>
            ))}
          </ul>
        )}

        <DialogFooter>
          <Button variant="outline" disabled={deleteTier.isPending} onClick={onClose}>
            {blockers ? __('Close', 'yayloyalty') : __('Cancel', 'yayloyalty')}
          </Button>
          {!blockers && (
            <LoadingButton variant="destructive" loading={deleteTier.isPending} onClick={confirm}>
              {__('Delete', 'yayloyalty')}
            </LoadingButton>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
