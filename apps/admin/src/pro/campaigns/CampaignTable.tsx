import { Fragment, ReactNode, useMemo, useRef, useState } from 'react';
import { closestCenter, DndContext, PointerSensor, useSensor, useSensors, type DragEndEvent } from '@dnd-kit/core';
import { restrictToVerticalAxis } from '@dnd-kit/modifiers';
import { arrayMove, SortableContext, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { GripVertical, InfoIcon, Lock, Search } from 'lucide-react';
import { useNavigate } from 'react-router';
import { toast } from 'sonner';
import { __, sprintf } from '@wordpress/i18n';

import { toastApiError } from '@/lib/api/api';
import { useCampaignsQuery, useUpdateCampaignStatusMutation } from '@/lib/queries/campaign.queries';
import { hasPerformancePage } from '@/lib/schema/campaign-performance.schema';
import {
  Campaign,
  CAMPAIGN_DISPLAY_STATUSES,
  CampaignDisplayStatus,
  findBuiltinCampaign,
  LITE_CAMPAIGN_TRIGGERS,
  resolveCampaignDisplayStatus,
} from '@/lib/schema/campaign.schema';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { BulkActionBox } from '@/components/ui/bulk-actions';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { BulkActionsMenu } from '@/components/ui/custom/bulk-actions-menu';
import { PointsValue } from '@/components/ui/custom/points-value';
import { YayLoyaltyToolTip } from '@/components/ui/custom/tooltip';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { InputGroup, InputGroupAddon, InputGroupInput } from '@/components/ui/input-group';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import EllipsisIcon from '@/components/icons/EllipsisIcon';
import { CampaignArchiveDialog, CampaignBulkArchiveDialog } from './archive/CampaignArchiveDialog';
import {
  CampaignGroupKey,
  conditionsLabel,
  givesLabel,
  scheduleLabel,
  statusLabel,
  triggerGroup,
  triggerLabel,
} from './campaign-labels';
import { CampaignPointsSummary, CampaignPointsSummarySkeleton } from './CampaignPointsSummary';
import { CampaignStatusBadge } from './CampaignStatusBadge';
import { CampaignPauseDialog } from './pause/CampaignPauseDialog';

/** Groups larger than this collapse to the default plus this many, highest-priority first. */
const VISIBLE_NON_DEFAULT_ROWS = 4;

/** Above this many campaigns, finding one by scrolling stops being reasonable. */
const SEARCH_THRESHOLD = 20;

/** Table columns, for colSpan on rows that span the whole width. */
const COLUMN_COUNT = 10;

const GROUP_ORDER: readonly CampaignGroupKey[] = ['purchase', 'signup', 'review', 'other'];

const GROUP_LABELS: Record<CampaignGroupKey, string> = {
  purchase: __('Purchase', 'yayloyalty'),
  signup: __('Signup', 'yayloyalty'),
  review: __('Review', 'yayloyalty'),
  other: __('Other', 'yayloyalty'),
};

/** Campaign plus the presentation strings this table shows, computed from real fields. */
interface DisplayCampaign extends Campaign {
  gives: string;
  conditionsLabel: string;
  scheduleLabel: string;
  displayStatus: CampaignDisplayStatus;
}

/** One trigger group as rendered; hiddenRows sit behind "Show the other … campaigns". */
interface CampaignGroup {
  key: CampaignGroupKey;
  rows: DisplayCampaign[];
  visibleRows: DisplayCampaign[];
  hiddenRows: DisplayCampaign[];
}

/**
 * Pro's grouped, prioritised campaigns table, reading the same list as
 * BuiltInCampaignList. Presentation strings are derived client-side
 * (toDisplayCampaign); points_issued is the server's own field.
 * Drag-to-reorder changes display order only, never `priority`.
 * `newStore` / `noLiveCampaign` are dev-only preview toggles with no UI
 * control; `newStore` stands in for "has this store ever earned points",
 * which nothing can query yet.
 */
export function CampaignTable() {
  const navigate = useNavigate();
  const [newStore] = useState(false);
  const [noLiveCampaign] = useState(false);

  const { data, isPending, isError } = useCampaignsQuery({ perPage: 100 });
  const allRows = useMemo(() => (data?.data ?? []).map(toDisplayCampaign), [data]);

  const [statusFilter, setStatusFilter] = useState<CampaignDisplayStatus | undefined>();
  const [searchQuery, setSearchQuery] = useState('');
  // Local override once a drag reorders a group; seeded lazily in reorderGroup().
  const [localRows, setLocalRows] = useState<DisplayCampaign[]>();
  const [expandedGroups, setExpandedGroups] = useState<ReadonlySet<CampaignGroupKey>>(() => new Set());
  const [selectedIds, setSelectedIds] = useState<ReadonlySet<number>>(() => new Set());
  // These dialogs open from state, not a Radix Trigger, so Radix has nothing of its own to
  // refocus on close; captured here and restored explicitly (see captureFocus/restoreFocus).
  const lastFocusedRef = useRef<HTMLElement | null>(null);
  const [archiveTarget, setArchiveTarget] = useState<DisplayCampaign>();
  // A snapshot, so a refetch while the dialog is open cannot change what it acts on.
  const [bulkArchiveTargets, setBulkArchiveTargets] = useState<DisplayCampaign[]>();
  const [pauseTarget, setPauseTarget] = useState<DisplayCampaign>();

  const baseRows = newStore ? allRows.filter((row) => row.is_builtin) : (localRows ?? allRows);
  const rows = noLiveCampaign ? baseRows.map(pauseIfActivePurchase) : baseRows;
  const archivedCount = rows.filter((row) => row.displayStatus === 'archived').length;
  const hasActivePurchaseCampaign = rows.some(
    (row) => triggerGroup(row.trigger) === 'purchase' && row.displayStatus === 'active',
  );

  // The one-click fix the "aren't earning points" alert below offers. Read off allRows, never
  // the toggled/filtered rows: the real built-in, regardless of dev preview state.
  const defaultPurchaseCampaign = findBuiltinCampaign(allRows, 'purchase');
  const reactivatePurchaseMutation = useUpdateCampaignStatusMutation(defaultPurchaseCampaign?.id ?? 0);

  async function reactivateDefaultPurchase() {
    if (!defaultPurchaseCampaign) {
      return;
    }

    try {
      await reactivatePurchaseMutation.mutateAsync('active');
      toast.success(__('"Points for purchases" is active again.', 'yayloyalty'));
    } catch (error) {
      await toastApiError(error, __('Could not switch the campaign back on.', 'yayloyalty'));
    }
  }

  const showSearch = rows.length > SEARCH_THRESHOLD;
  // Archived stays out entirely unless asked for by name.
  const statusFilteredRows = statusFilter
    ? rows.filter((row) => row.displayStatus === statusFilter)
    : rows.filter((row) => row.displayStatus !== 'archived');
  const search = searchQuery.trim().toLowerCase();
  const filteredRows =
    showSearch && search
      ? statusFilteredRows.filter((row) => row.name.toLowerCase().includes(search))
      : statusFilteredRows;

  const groups: CampaignGroup[] = GROUP_ORDER.map((key) => {
    const groupRows = filteredRows.filter((row) => triggerGroup(row.trigger) === key);

    return { key, rows: groupRows, ...splitCollapsedRows(groupRows, expandedGroups.has(key)) };
  }).filter((group) => group.rows.length > 0);

  // Selection counts only rows on screen, so a filtered-out or collapsed row is never archived unseen.
  const visibleRows = groups.flatMap((group) => group.visibleRows);
  const selectableRows = visibleRows.filter(isSelectable);
  const selectedRows = selectableRows.filter((row) => selectedIds.has(row.id));
  const allSelectedState =
    selectedRows.length === 0 ? false : selectedRows.length === selectableRows.length ? true : 'indeterminate';

  // The rows on screen change, so picks made against the old ones no longer apply.
  function changeStatusFilter(next: CampaignDisplayStatus | undefined) {
    setStatusFilter(next);
    setSelectedIds(new Set());
  }

  function toggleSelected(id: number) {
    setSelectedIds((current) => {
      const next = new Set(current);

      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }

      return next;
    });
  }

  function toggleAllSelected() {
    setSelectedIds(allSelectedState === true ? new Set() : new Set(selectableRows.map((row) => row.id)));
  }

  function deselectMany(ids: number[]) {
    setSelectedIds((current) => {
      const next = new Set(current);
      ids.forEach((id) => next.delete(id));

      return next;
    });
  }

  // The dropdown that opened this has already restored focus to its own trigger by the time
  // this runs (both are deferred with setTimeout for that reason); captured here since none of
  // these dialogs has a Trigger of its own for Radix to return focus to on close.
  function captureFocus() {
    lastFocusedRef.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
  }

  function restoreFocus() {
    const target = lastFocusedRef.current;
    lastFocusedRef.current = null;

    // Deferred so this runs after the closing dialog's own unmount, not before it.
    if (target) {
      setTimeout(() => document.contains(target) && target.focus(), 0);
    }
  }

  function openPause(row: DisplayCampaign) {
    captureFocus();
    setPauseTarget(row);
  }

  function openArchive(row: DisplayCampaign) {
    captureFocus();
    setArchiveTarget(row);
  }

  function archiveSelected() {
    captureFocus();

    // One campaign gets its own dialog, which has room for its figures.
    if (selectedRows.length === 1) {
      setArchiveTarget(selectedRows[0]);
    } else {
      setBulkArchiveTargets(selectedRows);
    }
  }

  function reorderGroup(groupRows: DisplayCampaign[], event: DragEndEvent) {
    const { active, over } = event;

    if (!over || active.id === over.id) {
      return;
    }

    const fromIndex = groupRows.findIndex((row) => row.id === active.id);
    const toIndex = groupRows.findIndex((row) => row.id === over.id);

    if (fromIndex === -1 || toIndex === -1) {
      return;
    }

    // Display order only; `priority` is a stored value dragging never touches.
    const reordered = arrayMove(groupRows, fromIndex, toIndex);
    const groupIds = new Set(groupRows.map((row) => row.id));

    setLocalRows((current) => {
      const base = current ?? rows;
      let next = 0;

      return base.map((row) => (groupIds.has(row.id) ? reordered[next++] : row));
    });
  }

  return (
    <>
      <Card>
        <CardContent className="flex flex-col gap-4">
          <div className="flex flex-wrap items-center gap-3">
            {isPending && (
              <div className="flex-1">
                <Skeleton className="h-4 w-64" />
              </div>
            )}

            {!isPending && (
              <p className="text-foreground flex-1 text-sm">
                {newStore ? (
                  __('Three ways customers earn points. Nothing has been awarded yet.', 'yayloyalty')
                ) : (
                  <>
                    {sprintf(
                      /* translators: %d: total number of campaigns, archived ones included. */
                      __('%d campaigns', 'yayloyalty'),
                      rows.length,
                    )}
                    {archivedCount > 0 && (
                      <>
                        {' - '}
                        <button
                          type="button"
                          className="text-foreground hover:underline"
                          onClick={() => changeStatusFilter('archived')}
                        >
                          {sprintf(
                            /* translators: %d: number of archived campaigns in the list. */
                            __('%d archived', 'yayloyalty'),
                            archivedCount,
                          )}
                        </button>
                      </>
                    )}
                    {__('. Drag to reorder within a group.', 'yayloyalty')}
                  </>
                )}
              </p>
            )}

            {/* Only once scrolling stops being a reasonable way to find one campaign. */}
            {showSearch && (
              <InputGroup size="small" className="w-52">
                <InputGroupAddon>
                  <Search className="size-4" />
                </InputGroupAddon>
                <InputGroupInput
                  placeholder={__('Search campaigns', 'yayloyalty')}
                  value={searchQuery}
                  onChange={(event) => setSearchQuery(event.target.value)}
                />
              </InputGroup>
            )}

            {/* Nothing to filter on a fresh install: three campaigns, all active. */}
            {!newStore && (
              <Select
                value={statusFilter ?? 'all'}
                onValueChange={(value) =>
                  changeStatusFilter(value === 'all' ? undefined : (value as CampaignDisplayStatus))
                }
              >
                <SelectTrigger size="sm">
                  <span className="text-muted-foreground">{__('Status:', 'yayloyalty')}</span>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">{__('All', 'yayloyalty')}</SelectItem>
                  {CAMPAIGN_DISPLAY_STATUSES.map((status) => (
                    <SelectItem key={status} value={status}>
                      {statusLabel(status)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}

            <Button size="sm" onClick={() => navigate('/campaigns/new')}>
              {__('Add campaign', 'yayloyalty')}
            </Button>
          </div>

          {/* Not dismissible: the storefront would keep promising points while nothing is awarded. */}
          {!isPending && !isError && !hasActivePurchaseCampaign && (
            <Alert variant="outline" className="gap-y-2">
              <InfoIcon />
              <AlertTitle className="line-clamp-none text-base">
                {__("Customers aren't earning points on purchases right now", 'yayloyalty')}
              </AlertTitle>
              <AlertDescription className="text-foreground gap-3">
                <p>
                  {__(
                    'Your storefront still advertises point earning, but completed orders are awarding nothing right now.',
                    'yayloyalty',
                  )}
                </p>
                <p>
                  {__(
                    '"Points for purchases" is paused, and no other purchase campaign is active right now. Signup and review campaigns are unaffected.',
                    'yayloyalty',
                  )}
                </p>
                {defaultPurchaseCampaign && (
                  <div className="flex flex-wrap items-center gap-3 pt-1">
                    <Button
                      variant="primary"
                      size="sm"
                      disabled={reactivatePurchaseMutation.isPending}
                      onClick={reactivateDefaultPurchase}
                    >
                      {reactivatePurchaseMutation.isPending
                        ? __('Switching on…', 'yayloyalty')
                        : __('Switch "Points for purchases" back on', 'yayloyalty')}
                    </Button>
                  </div>
                )}
              </AlertDescription>
            </Alert>
          )}

          {newStore && (
            <Alert variant="outline">
              <InfoIcon />
              <AlertDescription className="text-foreground flex-row items-center justify-between gap-3">
                <span>
                  {__(
                    'Your three campaigns are live and waiting. The first completed order will award points and fill the column on the right.',
                    'yayloyalty',
                  )}
                </span>
                <Button
                  variant="primary-outline-fill"
                  size="sm"
                  onClick={() => window.open(window.yayLoyaltyMeta.wpMeta.siteUrl, '_blank', 'noopener')}
                >
                  {__('View your storefront', 'yayloyalty')}
                </Button>
              </AlertDescription>
            </Alert>
          )}

          {isPending && <CampaignTableSkeleton />}

          {isError && <p className="text-destructive text-sm">{__('Could not load campaigns.', 'yayloyalty')}</p>}

          {!isPending && !isError && groups.length === 0 && (
            <Empty>
              <EmptyHeader>
                <EmptyTitle>{__('No campaigns match this filter', 'yayloyalty')}</EmptyTitle>
                <EmptyDescription>{__('Try a different status.', 'yayloyalty')}</EmptyDescription>
              </EmptyHeader>
            </Empty>
          )}

          {!isPending && !isError && groups.length > 0 && (
            <div className="overflow-hidden rounded-lg border">
              <div className="overflow-x-auto">
                <Table>
                  <CampaignTableHeader
                    selectAll={
                      <Checkbox
                        checked={allSelectedState}
                        onCheckedChange={toggleAllSelected}
                        disabled={selectableRows.length === 0}
                        aria-label={__('Select all campaigns', 'yayloyalty')}
                      />
                    }
                  />
                  <TableBody>
                    {groups.map((group) => (
                      <CampaignGroupSection
                        key={group.key}
                        group={group}
                        selectedIds={selectedIds}
                        onToggleSelected={toggleSelected}
                        onExpand={() => setExpandedGroups((current) => new Set(current).add(group.key))}
                        onReorder={reorderGroup}
                        onPause={openPause}
                        onArchive={openArchive}
                      />
                    ))}
                  </TableBody>
                </Table>
              </div>
            </div>
          )}

          <CampaignBulkActionBar
            count={selectedRows.length}
            onClear={() => setSelectedIds(new Set())}
            onArchive={archiveSelected}
          />
        </CardContent>
      </Card>

      {isPending ? <CampaignPointsSummarySkeleton /> : <CampaignPointsSummary rows={rows} />}

      {archiveTarget && (
        <CampaignArchiveDialog
          campaign={archiveTarget}
          onClose={() => {
            setArchiveTarget(undefined);
            restoreFocus();
          }}
          onDone={() => deselectMany([archiveTarget.id])}
          onViewArchived={() => changeStatusFilter('archived')}
        />
      )}

      {bulkArchiveTargets && (
        <CampaignBulkArchiveDialog
          campaigns={bulkArchiveTargets}
          onClose={() => {
            setBulkArchiveTargets(undefined);
            restoreFocus();
          }}
          onDone={() => deselectMany(bulkArchiveTargets.map((row) => row.id))}
          onViewArchived={() => changeStatusFilter('archived')}
        />
      )}

      {pauseTarget && (
        <CampaignPauseDialog
          campaign={pauseTarget}
          onClose={() => {
            setPauseTarget(undefined);
            restoreFocus();
          }}
        />
      )}
    </>
  );
}

/** The one column header row; the loading table renders it too, so the columns never shift when data lands. */
function CampaignTableHeader({ selectAll }: { selectAll: ReactNode }) {
  return (
    <TableHeader>
      <TableRow>
        <TableHead className="w-8">{selectAll}</TableHead>
        <TableHead className="w-6" />
        <TableHead>{__('Campaign', 'yayloyalty')}</TableHead>
        <TableHead>{__('Trigger', 'yayloyalty')}</TableHead>
        <TableHead>{__('Gives', 'yayloyalty')}</TableHead>
        <TableHead>{__('Conditions', 'yayloyalty')}</TableHead>
        <TableHead>{__('Schedule', 'yayloyalty')}</TableHead>
        <TableHead className="text-right">{__('Points issued', 'yayloyalty')}</TableHead>
        <TableHead>{__('Status', 'yayloyalty')}</TableHead>
        <TableHead className="w-1" />
      </TableRow>
    </TableHeader>
  );
}

/** Every store has at least the built-ins, one per Lite trigger's group; the placeholder draws exactly that. */
const SKELETON_GROUPS: readonly CampaignGroupKey[] = LITE_CAMPAIGN_TRIGGERS.map(triggerGroup);

/** Mirrors the loaded table: real header, a group band and one row per group, cell for cell. */
function CampaignTableSkeleton() {
  return (
    <div className="overflow-hidden rounded-lg border">
      <div className="overflow-x-auto">
        <Table>
          <CampaignTableHeader
            selectAll={<Checkbox disabled aria-label={__('Select all campaigns', 'yayloyalty')} />}
          />
          <TableBody>
            {SKELETON_GROUPS.map((key) => (
              <Fragment key={key}>
                <TableRow className="bg-secondary hover:bg-secondary">
                  <TableCell colSpan={COLUMN_COUNT} className="h-9 py-0">
                    <span className="text-xs font-semibold tracking-wide uppercase">{GROUP_LABELS[key]}</span>
                  </TableCell>
                </TableRow>
                <TableRow className="hover:bg-transparent">
                  <TableCell>
                    <Checkbox disabled aria-hidden />
                  </TableCell>
                  <TableCell className="px-1">
                    <GripVertical className="text-muted-foreground/40 size-4" />
                  </TableCell>
                  <TableCell>
                    <div className="flex items-center gap-1.5">
                      <Skeleton className="h-4 w-36" />
                      <Skeleton className="h-5 w-16 rounded-full" />
                    </div>
                  </TableCell>
                  <TableCell>
                    <Skeleton className="h-3 w-24" />
                  </TableCell>
                  <TableCell>
                    <Skeleton className="h-3 w-20" />
                  </TableCell>
                  <TableCell>
                    <Skeleton className="h-3 w-20" />
                  </TableCell>
                  <TableCell>
                    <Skeleton className="h-3 w-20" />
                  </TableCell>
                  <TableCell>
                    <Skeleton className="ml-auto h-3 w-10" />
                  </TableCell>
                  <TableCell>
                    <Skeleton className="h-5 w-14 rounded-full" />
                  </TableCell>
                  <TableCell>
                    <Skeleton className="size-6" />
                  </TableCell>
                </TableRow>
              </Fragment>
            ))}
          </TableBody>
        </Table>
      </div>
    </div>
  );
}

/**
 * YayUiKits' BulkActionBox with the shared BulkActionsMenu, as CustomersBulkActionBar uses it.
 * Archive is the only bulk action: it is the only one with a designed
 * confirmation, and a dead control is worse than none.
 */
function CampaignBulkActionBar({
  count,
  onClear,
  onArchive,
}: {
  count: number;
  onClear: () => void;
  onArchive: () => void;
}) {
  return (
    <BulkActionBox selected={count} onClose={onClear} className="w-fit">
      <BulkActionsMenu count={count} contentClassName="min-w-30">
        {/* Deferred so the dropdown closes and restores focus to this trigger before the dialog opens. */}
        <DropdownMenuItem variant="destructive" onSelect={() => setTimeout(onArchive, 0)}>
          {__('Archive', 'yayloyalty')}
        </DropdownMenuItem>
      </BulkActionsMenu>
    </BulkActionBox>
  );
}

function CampaignGroupSection({
  group,
  selectedIds,
  onToggleSelected,
  onExpand,
  onReorder,
  onPause,
  onArchive,
}: {
  group: CampaignGroup;
  selectedIds: ReadonlySet<number>;
  onToggleSelected: (id: number) => void;
  onExpand: () => void;
  onReorder: (groupRows: DisplayCampaign[], event: DragEndEvent) => void;
  onPause: (row: DisplayCampaign) => void;
  onArchive: (row: DisplayCampaign) => void;
}) {
  const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 4 } }));
  // "Topmost wins" means nothing until there is a second campaign to lose.
  const hasPriorityToResolve = group.key === 'purchase' && group.rows.length > 1;
  const { visibleRows, hiddenRows } = group;

  // A tie is scoped to the group: priority only compares campaigns sharing a trigger.
  const priorityCounts = new Map<number, number>();
  for (const row of group.rows) {
    priorityCounts.set(row.priority, (priorityCounts.get(row.priority) ?? 0) + 1);
  }

  return (
    <>
      <TableRow className="bg-secondary hover:bg-secondary">
        <TableCell colSpan={COLUMN_COUNT} className="h-9 py-0">
          <span className="text-xs font-semibold tracking-wide uppercase">{GROUP_LABELS[group.key]}</span>
          <span className="text-muted-foreground ml-2 inline-flex items-center gap-1 text-xs">
            {campaignCountLabel(group.rows.length)}
            {hasPriorityToResolve && (
              <>
                {` · ${__('highest priority wins', 'yayloyalty')}`}
                <YayLoyaltyToolTip
                  content={__(
                    'For each order, the topmost matching campaign applies. Others are skipped.',
                    'yayloyalty',
                  )}
                />
              </>
            )}
          </span>
        </TableCell>
      </TableRow>

      <DndContext
        sensors={sensors}
        collisionDetection={closestCenter}
        modifiers={[restrictToVerticalAxis]}
        onDragEnd={(event) => onReorder(visibleRows, event)}
        // The screen-reader announcements are <div>s; rendered here they would sit inside <tbody>.
        accessibility={{ container: document.body }}
      >
        <SortableContext items={visibleRows.map((row) => row.id)} strategy={verticalListSortingStrategy}>
          {visibleRows.map((row) => (
            <SortableCampaignRow
              key={row.id}
              row={row}
              isTied={(priorityCounts.get(row.priority) ?? 0) > 1}
              selected={selectedIds.has(row.id)}
              onToggleSelected={onToggleSelected}
              onPause={onPause}
              onArchive={onArchive}
            />
          ))}
        </SortableContext>
      </DndContext>

      {hiddenRows.length > 0 && (
        <TableRow className="hover:bg-transparent">
          <TableCell colSpan={COLUMN_COUNT} className="py-2">
            <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
              {/* Only the visible rows are ever in the DndContext above, so a drag can't reach a collapsed one. */}
              <Button variant="link" size="sm" className="h-auto p-0" onClick={onExpand}>
                {showMoreLabel(group.key)}
              </Button>
              <span className="text-muted-foreground text-xs">
                {sprintf(
                  /* translators: %s: comma-separated priority numbers of the hidden campaigns. */
                  __('priorities %s', 'yayloyalty'),
                  hiddenRows.map((row) => row.priority).join(', '),
                )}
              </span>
            </div>
          </TableCell>
        </TableRow>
      )}
    </>
  );
}

/** "Show the other purchase campaigns"; bare "other" for the catch-all group, to avoid "other Other". */
function showMoreLabel(group: CampaignGroupKey): string {
  if (group === 'other') {
    return __('Show the other campaigns', 'yayloyalty');
  }

  return sprintf(
    /* translators: %s: the group's label (Purchase, Signup, Review), lowercased. */
    __('Show the other %s campaigns', 'yayloyalty'),
    GROUP_LABELS[group].toLowerCase(),
  );
}

/** One draggable row within a CampaignGroupSection. */
function SortableCampaignRow({
  row,
  isTied,
  selected,
  onToggleSelected,
  onPause,
  onArchive,
}: {
  row: DisplayCampaign;
  isTied: boolean;
  selected: boolean;
  onToggleSelected: (id: number) => void;
  onPause: (row: DisplayCampaign) => void;
  onArchive: (row: DisplayCampaign) => void;
}) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: row.id });
  const navigate = useNavigate();

  return (
    <TableRow
      ref={setNodeRef}
      style={{ transform: CSS.Transform.toString(transform), transition }}
      data-state={isDragging || selected ? 'selected' : undefined}
    >
      <TableCell>
        {/* Same rule as the menu: a built-in is drawn disabled with its reason, an archived row has nothing to select. */}
        {row.displayStatus !== 'archived' && (
          <Checkbox
            checked={selected}
            disabled={row.is_builtin}
            onCheckedChange={() => onToggleSelected(row.id)}
            aria-label={sprintf(
              row.is_builtin
                ? /* translators: %s: the campaign's name. */
                  __('Select “%s” (default campaigns can’t be archived)', 'yayloyalty')
                : /* translators: %s: the campaign's name. */
                  __('Select “%s”', 'yayloyalty'),
              row.name,
            )}
          />
        )}
      </TableCell>
      <TableCell className="px-1">
        <button
          type="button"
          className="text-muted-foreground flex cursor-grab touch-none items-center active:cursor-grabbing"
          aria-label={__('Drag to reorder', 'yayloyalty')}
          {...attributes}
          {...listeners}
        >
          <GripVertical className="size-4" />
        </button>
      </TableCell>
      <TableCell>
        <div className="flex items-center gap-1.5">
          <Button
            variant="link"
            size="sm"
            className="h-auto p-0 font-semibold"
            onClick={() => navigate(`/campaigns/${row.id}/edit`)}
          >
            {row.name}
          </Button>
          {isTied && (
            <YayLoyaltyToolTip
              trigger={<Badge variant="outline">{__('Priority tie', 'yayloyalty')}</Badge>}
              content={__('Shares this priority with another campaign in this group', 'yayloyalty')}
            />
          )}
          {row.is_builtin && (
            <YayLoyaltyToolTip
              trigger={<Lock className="text-muted-foreground size-3" />}
              content={__('Built-in campaigns cannot be archived.', 'yayloyalty')}
            />
          )}
        </div>
      </TableCell>
      <TableCell className="text-muted-foreground text-xs">{triggerLabel(row.trigger)}</TableCell>
      <TableCell className="text-xs">{row.gives}</TableCell>
      <TableCell className="text-muted-foreground text-xs">{row.conditionsLabel}</TableCell>
      <TableCell className="text-muted-foreground text-xs">{row.scheduleLabel}</TableCell>
      <TableCell className="text-muted-foreground text-right text-xs tabular-nums">
        <PointsValue value={row.points_issued} />
      </TableCell>
      <TableCell>
        <CampaignStatusBadge status={row.displayStatus} />
      </TableCell>
      <TableCell className="text-right">
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <button
              type="button"
              className="text-muted-foreground flex size-6 items-center justify-center"
              aria-label={__('Campaign actions', 'yayloyalty')}
            >
              <EllipsisIcon />
            </button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            {hasPerformancePage(row.trigger) && (
              <DropdownMenuItem onSelect={() => navigate(`/campaigns/${row.id}/performance`)}>
                {__('View performance', 'yayloyalty')}
              </DropdownMenuItem>
            )}
            {/* Create mode, seeded from this campaign — CampaignCreator's duplicateFrom path.
                A real POST on Save; this campaign itself is never written to. */}
            <DropdownMenuItem onSelect={() => navigate(`/campaigns/new/scratch?from=${row.id}`)}>
              {__('Duplicate', 'yayloyalty')}
            </DropdownMenuItem>
            {/* Shown for built-ins too, as their substitute for Archive. Deferred so the dropdown closes first. */}
            {(row.displayStatus === 'active' || row.displayStatus === 'scheduled') && (
              <DropdownMenuItem onSelect={() => setTimeout(() => onPause(row), 0)}>
                {__('Pause', 'yayloyalty')}
              </DropdownMenuItem>
            )}
            {/* Nothing to archive on an archived row. The dialog itself decides archive vs delete. */}
            {row.displayStatus !== 'archived' && !row.is_builtin && (
              // Deferred so the dropdown closes and restores focus to this trigger before the dialog opens.
              <DropdownMenuItem variant="destructive" onSelect={() => setTimeout(() => onArchive(row), 0)}>
                {__('Archive', 'yayloyalty')}
              </DropdownMenuItem>
            )}
            {/* Drawn disabled with its reason rather than omitted: someone who came looking for
                Archive gets an answer instead of an absence. */}
            {row.is_builtin && (
              <>
                <DropdownMenuItem variant="destructive" disabled aria-describedby={`campaign-archive-reason-${row.id}`}>
                  {__('Archive', 'yayloyalty')}
                  <span className="ml-auto text-[10px] font-semibold tracking-wide uppercase">
                    {__('Not available', 'yayloyalty')}
                  </span>
                </DropdownMenuItem>
                <p
                  id={`campaign-archive-reason-${row.id}`}
                  className="text-muted-foreground max-w-60 px-2 pb-1.5 text-xs"
                >
                  {__('Default campaigns can’t be archived. You can pause one instead.', 'yayloyalty')}
                </p>
              </>
            )}
          </DropdownMenuContent>
        </DropdownMenu>
      </TableCell>
    </TableRow>
  );
}

/** The default stays visible regardless of rank; the rest cap at VISIBLE_NON_DEFAULT_ROWS until expanded. */
function splitCollapsedRows(
  groupRows: DisplayCampaign[],
  expanded: boolean,
): Pick<CampaignGroup, 'visibleRows' | 'hiddenRows'> {
  const hiddenRows = expanded ? [] : groupRows.filter((row) => !row.is_builtin).slice(VISIBLE_NON_DEFAULT_ROWS);
  const hiddenIds = new Set(hiddenRows.map((row) => row.id));

  return { visibleRows: groupRows.filter((row) => !hiddenIds.has(row.id)), hiddenRows };
}

/** A built-in can never be archived, and an archived row has nothing left to archive. */
function isSelectable(row: DisplayCampaign): boolean {
  return !row.is_builtin && row.displayStatus !== 'archived';
}

/** For the `noLiveCampaign` preview: a purchase campaign that was active no longer is. */
function pauseIfActivePurchase(row: DisplayCampaign): DisplayCampaign {
  if (triggerGroup(row.trigger) === 'purchase' && row.displayStatus === 'active') {
    return { ...row, displayStatus: 'paused' };
  }

  return row;
}

function campaignCountLabel(count: number): string {
  if (count === 1) {
    return __('1 campaign', 'yayloyalty');
  }

  return sprintf(
    /* translators: %d: number of campaigns in this priority group. */
    __('%d campaigns', 'yayloyalty'),
    count,
  );
}

/** Derives every presentation string from the real Campaign fields, for any trigger. */
function toDisplayCampaign(campaign: Campaign): DisplayCampaign {
  return {
    ...campaign,
    gives: givesLabel(campaign.trigger, campaign.config),
    conditionsLabel: conditionsLabel(campaign.conditions),
    scheduleLabel: scheduleLabel(campaign.starts_at, campaign.ends_at),
    displayStatus: resolveCampaignDisplayStatus(campaign.status, campaign.starts_at, campaign.ends_at),
  };
}
