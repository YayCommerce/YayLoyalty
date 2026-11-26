import { useState } from 'react';
import { closestCenter, DndContext, PointerSensor, useSensor, useSensors, type DragEndEvent } from '@dnd-kit/core';
import { restrictToVerticalAxis } from '@dnd-kit/modifiers';
import { arrayMove, SortableContext, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { GripVertical, InfoIcon, Plus, Search } from 'lucide-react';
import { useNavigate } from 'react-router';
import { __, _n, sprintf } from '@wordpress/i18n';

import { toastApiError } from '@/lib/api/api';
import { useReorderRewardsMutation, useRewardsQuery } from '@/lib/queries/reward.queries';
import { Reward } from '@/lib/schema/reward.schema';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { ErrorState } from '@/components/ui/custom/error-state';
import { NumberedPagination } from '@/components/ui/custom/numbered-pagination';
import { InputGroup, InputGroupAddon, InputGroupInput } from '@/components/ui/input-group';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { RewardArchiveDialog } from './archive/RewardArchiveDialog';
import { resolveRewardsScreen, RewardsScreen } from './reward-labels';
import { RewardTableRow } from './RewardTableRow';

const PER_PAGE = 10;

/**
 * Pro's Rewards screen: the launcher catalog in display order, plus an
 * Archived view reached from the header. Rows open the reward editor modal,
 * and the ⋯ menu pauses, resumes, duplicates, archives, restores or deletes.
 * Search and paging are client-side over the one loaded list.
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
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);

  const needle = search.trim().toLowerCase();
  const viewRows = (showArchived ? screen.archivedRows : screen.rows).filter(
    (row) => !needle || row.name.toLowerCase().includes(needle),
  );
  const pageCount = Math.max(1, Math.ceil(viewRows.length / PER_PAGE));
  // A search or a view switch can leave `page` past the end; clamp rather than show an empty page.
  const currentPage = Math.min(page, pageCount);
  const pageRows = viewRows.slice((currentPage - 1) * PER_PAGE, currentPage * PER_PAGE);
  // Dragging a filtered list would save an order the merchant cannot see.
  const dragDisabled = needle !== '';

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
              <Skeleton className="h-5 w-40" />
            ) : (
              <RewardTableTitle screen={screen} showArchived={showArchived} onViewChange={setView} />
            )}
            <div className="flex flex-wrap items-center gap-2">
              <InputGroup className="w-60">
                <InputGroupAddon>
                  <Search className="size-4" />
                </InputGroupAddon>
                <InputGroupInput
                  placeholder={__('Search', 'yayloyalty')}
                  aria-label={__('Search rewards', 'yayloyalty')}
                  value={search}
                  onChange={(event) => {
                    setSearch(event.target.value);
                    setPage(1);
                  }}
                />
              </InputGroup>
              <Button onClick={() => navigate('/rewards/new')}>
                <Plus />
                {__('Add reward', 'yayloyalty')}
              </Button>
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
                      {pageRows.length === 0 && (
                        <TableRow className="hover:bg-transparent">
                          <TableCell colSpan={8} className="text-muted-foreground py-8 text-center">
                            {__('No rewards match this search.', 'yayloyalty')}
                          </TableCell>
                        </TableRow>
                      )}
                      <SortableContext items={pageRows.map((row) => row.id)} strategy={verticalListSortingStrategy}>
                        {pageRows.map((row) => (
                          <RewardTableRow
                            key={row.id}
                            reward={row}
                            dragDisabled={dragDisabled}
                            onArchiveOrDelete={setArchiveTarget}
                          />
                        ))}
                      </SortableContext>
                    </TableBody>
                  </Table>
                </DndContext>
              </div>
            </div>
          )}

          {ready && (
            <div className="flex flex-wrap items-center justify-between gap-3">
              {!showArchived && (
                <p className="text-muted-foreground m-0 flex items-center gap-1.5 text-xs">
                  <InfoIcon className="size-3.5" />
                  {__('Drag rewards to change the order customers see. Cart discount stays first.', 'yayloyalty')}
                </p>
              )}
              {pageCount > 1 && (
                <NumberedPagination
                  page={currentPage}
                  pageCount={pageCount}
                  isLoading={false}
                  onPrevious={() => setPage(Math.max(1, currentPage - 1))}
                  onNext={() => setPage(Math.min(pageCount, currentPage + 1))}
                  onPageChange={setPage}
                  className="ms-auto"
                />
              )}
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

/** "All rewards" with an "{n} archived" link, or the Archived view's title and its way back. */
function RewardTableTitle({
  screen,
  showArchived,
  onViewChange,
}: {
  screen: RewardsScreen;
  showArchived: boolean;
  onViewChange: (view: 'catalog' | 'archived') => void;
}) {
  const archivedCount = screen.archivedRows.length;

  return (
    <div className="flex flex-wrap items-baseline gap-2">
      <h2 className="m-0 text-base font-semibold">
        {showArchived ? __('Archived rewards', 'yayloyalty') : __('All rewards', 'yayloyalty')}
      </h2>
      {showArchived ? (
        <button
          type="button"
          className="text-muted-foreground cursor-pointer text-sm hover:underline"
          onClick={() => onViewChange('catalog')}
        >
          {__('Back to rewards', 'yayloyalty')}
        </button>
      ) : (
        archivedCount > 0 && (
          <button
            type="button"
            className="text-muted-foreground cursor-pointer text-sm hover:underline"
            onClick={() => onViewChange('archived')}
          >
            {sprintf(
              /* translators: %d: number of archived rewards. */
              _n('%d archived', '%d archived', archivedCount, 'yayloyalty'),
              archivedCount,
            )}
          </button>
        )
      )}
    </div>
  );
}

/** Shared between the real table and its loading skeleton, so column widths never drift between the two. */
function RewardTableHeader() {
  return (
    <TableHeader className="bg-muted/50">
      <TableRow>
        <TableHead className="w-8" />
        <TableHead>{__('Reward', 'yayloyalty')}</TableHead>
        <TableHead className="text-center">{__('Type', 'yayloyalty')}</TableHead>
        <TableHead className="text-center">{__('Points cost', 'yayloyalty')}</TableHead>
        <TableHead>{__('Availability', 'yayloyalty')}</TableHead>
        <TableHead className="text-center">{__('Redeemed', 'yayloyalty')}</TableHead>
        <TableHead>{__('Status', 'yayloyalty')}</TableHead>
        <TableHead className="w-1" />
      </TableRow>
    </TableHeader>
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
