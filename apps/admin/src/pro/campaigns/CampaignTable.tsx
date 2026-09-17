import { useMemo, useState } from 'react';
import { closestCenter, DndContext, PointerSensor, useSensor, useSensors, type DragEndEvent } from '@dnd-kit/core';
import { restrictToVerticalAxis } from '@dnd-kit/modifiers';
import { arrayMove, SortableContext, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { GripVertical, InfoIcon, Lock, Search } from 'lucide-react';
import { useNavigate } from 'react-router';
import { __, sprintf } from '@wordpress/i18n';

import { parseWPDate } from '@/lib/helpers/format.helper';
import { useCampaignsQuery } from '@/lib/queries/campaign.queries';
import {
  Campaign,
  CAMPAIGN_DISPLAY_STATUSES,
  CampaignDisplayStatus,
  CampaignStatus,
  resolveCampaignDisplayStatus,
} from '@/lib/schema/campaign.schema';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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
import {
  CampaignGroupKey,
  conditionsLabel,
  givesLabel,
  statusLabel,
  triggerGroup,
  triggerLabel,
} from './campaign-labels';
import { DUMMY_POINTS_LAST_30_DAYS, hasPointsFigure } from './campaign-points-dummy';
import { CampaignPointsSummary } from './CampaignPointsSummary';
import type { CampaignEditorDuplicateSeed } from './editor/CampaignEditor';

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

/** `Campaign` plus the presentation strings this table shows, computed once from real fields — never a second fetch. */
interface DisplayCampaign extends Campaign {
  gives: string;
  conditionsLabel: string;
  scheduleLabel: string;
  displayStatus: CampaignDisplayStatus;
  /** Dummy, client-side only — see campaign-points-dummy.ts. */
  pointsLast30Days: number | null;
}

/**
 * Pro's grouped, prioritised campaigns table — tree-shaken out of the Lite
 * bundle with the rest of `src/pro/`. Lite keeps its own flat, real list in
 * BuiltInCampaignList (a real feature, not gated behind an upgrade overlay).
 *
 * ONE LIST, NOT TWO: reads `useCampaignsQuery()` from `@/lib/queries/campaign.queries`
 * — the same real hook BuiltInCampaignList reads (there, filtered to
 * `is_builtin`; here, shown whole). The campaign fixture behind it lives
 * entirely in CampaignRestController's SWAP POINT; this component has no
 * campaign data of its own. `gives`/`conditionsLabel`/`scheduleLabel`/
 * `displayStatus` are derived client-side from the real
 * `config`/`conditions`/`status`/dates — see toDisplayCampaign() — because
 * the real Campaign shape has no room for precomputed presentation strings,
 * by design. `pointsLast30Days` is the one exception: it is genuinely dummy,
 * client-side-only data (see campaign-points-dummy.ts) because no endpoint
 * yet exposes a per-campaign 30-day figure for the whole list.
 *
 * Drag-to-reorder changes display order only, never `priority` (a separate
 * stored value) — scoped per group, since priority only ever compares
 * campaigns sharing a trigger.
 *
 * `newStore` and `noLiveCampaign` are dev-only preview toggles: flip the
 * defaults below, or edit live via React DevTools. No UI control, on purpose.
 *
 * `newStore` filters the same fetched list down to `is_builtin` rather than
 * swapping in a second fixture, because that is genuinely all a real fresh
 * store would have. It is a STAND-IN, though, for a fact this build cannot
 * observe yet: a store that later deletes every custom campaign would look
 * identical to one that never made any, and the real "new store" signal is
 * store-wide and lifetime — has this account ever recorded an earn ledger
 * entry (LedgerRepository has no such query today; sum_points_for_user() is
 * per-customer, not store-wide). Once that exists, `newStore` becomes
 * `!hasEverEarnedPoints`; `rows` already reads one list either way.
 */
export function CampaignTable() {
  const [newStore] = useState(false);
  const [noLiveCampaign] = useState(false);

  const { data, isPending, isError } = useCampaignsQuery({ perPage: 100 });
  const allRows = useMemo(() => (data?.data ?? []).map(toDisplayCampaign), [data]);

  const [statusFilter, setStatusFilter] = useState<CampaignDisplayStatus | undefined>();
  const [searchQuery, setSearchQuery] = useState('');
  // Local override of fetched order/priority once a drag reorders a group. Seeded lazily from
  // `allRows` rather than via an effect — see reorderGroup().
  const [localRows, setLocalRows] = useState<DisplayCampaign[]>();

  const baseRows = newStore ? allRows.filter((row) => row.is_builtin) : (localRows ?? allRows);
  const rows = noLiveCampaign ? baseRows.map(pauseIfActivePurchase) : baseRows;
  const archivedCount = rows.filter((row) => row.displayStatus === 'archived').length;
  const hasActivePurchaseCampaign = rows.some(
    (row) => triggerGroup(row.trigger) === 'purchase' && row.displayStatus === 'active',
  );
  const showSearch = rows.length > SEARCH_THRESHOLD;
  // Archived is retired, not merely another status: it stays out of the
  // table entirely unless a merchant asks for it by name. Any other filter
  // (including no filter at all) already excludes it via the equality check.
  const statusFilteredRows = statusFilter
    ? rows.filter((row) => row.displayStatus === statusFilter)
    : rows.filter((row) => row.displayStatus !== 'archived');
  const search = searchQuery.trim().toLowerCase();
  const filteredRows =
    showSearch && search
      ? statusFilteredRows.filter((row) => row.name.toLowerCase().includes(search))
      : statusFilteredRows;

  const groups = GROUP_ORDER.map((key) => ({
    key,
    rows: filteredRows.filter((row) => triggerGroup(row.trigger) === key),
  })).filter((group) => group.rows.length > 0);

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

    // Display order only. `priority` is a separate, stored value set
    // through the campaign write API (no SPA editor yet) — dragging never reads or writes it, it only changes
    // which slot in the group each row's own (unchanged) row occupies.
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
                        onClick={() => setStatusFilter('archived')}
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

            {/*
            Filtering by status is not worth the clutter on a fresh install —
            three campaigns, all ACTIVE, nothing to filter down to.
          */}
            {!newStore && (
              <Select
                value={statusFilter ?? 'all'}
                onValueChange={(value) =>
                  setStatusFilter(value === 'all' ? undefined : (value as CampaignDisplayStatus))
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

            {/*
              Inert by decision, not by missing wiring: campaigns/:id/edit is a real, working
              editor (see pro/campaigns/editor/CampaignEditor.tsx), but create_item() is not a
              SWAP POINT — it persists for real — while get_items()/get_item() still answer from
              CampaignRestController's dummy fixture. A created row would never appear in this
              list. Stays inert until the list swaps off the fixture; see that controller's class
              docblock.
            */}
            <Button size="sm" onClick={() => {}}>
              {__('Add campaign', 'yayloyalty')}
            </Button>
          </div>

          {/*
            Not dismissible, deliberately: a closed notice would leave the
            storefront still promising points while nothing is being
            awarded. This can only go away by fixing the actual condition —
            an active purchase campaign, or the storefront copy switched off.
          */}
          {!hasActivePurchaseCampaign && (
            <Alert variant="outline" className="gap-y-2">
              <InfoIcon />
              <AlertTitle className="line-clamp-none text-base">
                {__("Customers aren't earning points on purchases right now", 'yayloyalty')}
              </AlertTitle>
              <AlertDescription className="text-foreground gap-3">
                <p>
                  {__(
                    'Your storefront still advertises point earning. Since 6 September, 47 orders have completed and awarded nothing.',
                    'yayloyalty',
                  )}
                </p>
                <p>
                  {__(
                    '"Points for purchases" is paused, and no other purchase campaign is active right now. Signup and review campaigns are unaffected.',
                    'yayloyalty',
                  )}
                </p>
                <div className="flex flex-wrap items-center gap-3 pt-1">
                  {/* Inert: no write client/mutation wired yet (routes exist in CampaignRestController). */}
                  <Button variant="primary" size="sm" onClick={() => {}}>
                    {__('Switch "Points for purchases" back on', 'yayloyalty')}
                  </Button>
                  <Button variant="outline" size="sm" onClick={() => {}}>
                    {__('Hide earning messages on the storefront', 'yayloyalty')}
                  </Button>
                </div>
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

          {isPending && <Skeleton className="h-64 w-full" />}

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
                  <TableHeader>
                    <TableRow>
                      <TableHead className="w-6" />
                      <TableHead>{__('Campaign', 'yayloyalty')}</TableHead>
                      <TableHead>{__('Trigger', 'yayloyalty')}</TableHead>
                      <TableHead>{__('Gives', 'yayloyalty')}</TableHead>
                      <TableHead>{__('Conditions', 'yayloyalty')}</TableHead>
                      <TableHead>{__('Schedule', 'yayloyalty')}</TableHead>
                      <TableHead className="text-right">{__('Last 30 days', 'yayloyalty')}</TableHead>
                      <TableHead className="text-center">{__('Priority', 'yayloyalty')}</TableHead>
                      <TableHead>{__('Status', 'yayloyalty')}</TableHead>
                      <TableHead className="w-1" />
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {groups.map((group) => (
                      <CampaignGroupSection key={group.key} group={group} onReorder={reorderGroup} />
                    ))}
                  </TableBody>
                </Table>
              </div>
            </div>
          )}

          {/* Not shown on a fresh install — nothing there is paused or draft to explain yet. */}
          {!newStore && (
            <p className="text-muted-foreground max-w-3xl text-xs">
              {__(
                'Paused keeps the campaign and its history but stops it matching. Draft has never run and is invisible to the engine.',
                'yayloyalty',
              )}
            </p>
          )}
        </CardContent>
      </Card>

      <CampaignPointsSummary rows={rows} />
    </>
  );
}

function CampaignGroupSection({
  group,
  onReorder,
}: {
  group: { key: CampaignGroupKey; rows: DisplayCampaign[] };
  onReorder: (groupRows: DisplayCampaign[], event: DragEndEvent) => void;
}) {
  const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 4 } }));
  // Drag handles are drawn but inert in a group of one — likewise this
  // explanation: nothing about "topmost wins" matters until there is a
  // second campaign to lose.
  const hasPriorityToResolve = group.key === 'purchase' && group.rows.length > 1;

  // Defaults stay visible regardless of rank — hiding the fallback campaign
  // behind a click would be a strange kind of surprise. Everything else caps
  // at VISIBLE_NON_DEFAULT_ROWS, highest priority first, so the ones that
  // actually decide an order stay in view without a click.
  const [expanded, setExpanded] = useState(false);
  const nonDefaultRows = group.rows.filter((row) => !row.is_builtin);
  const hiddenRows = expanded ? [] : nonDefaultRows.slice(VISIBLE_NON_DEFAULT_ROWS);
  const hiddenIds = new Set(hiddenRows.map((row) => row.id));
  const visibleRows = group.rows.filter((row) => !hiddenIds.has(row.id));

  // Priority only ever compares campaigns sharing a trigger, so a tie is
  // scoped to this group — the same reason drag-to-reorder is.
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
      >
        <SortableContext items={visibleRows.map((row) => row.id)} strategy={verticalListSortingStrategy}>
          {visibleRows.map((row) => (
            <SortableCampaignRow key={row.id} row={row} isTied={(priorityCounts.get(row.priority) ?? 0) > 1} />
          ))}
        </SortableContext>
      </DndContext>

      {hiddenRows.length > 0 && (
        <TableRow className="hover:bg-transparent">
          <TableCell colSpan={COLUMN_COUNT} className="py-2">
            <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
              {/* Only the visible rows are ever in the DndContext above, so a drag can't reach a collapsed one. */}
              <Button variant="link" size="sm" className="h-auto p-0" onClick={() => setExpanded(true)}>
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

/** "Show the other purchase campaigns" — "other" on its own for the catch-all group, to avoid "other Other". */
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
function SortableCampaignRow({ row, isTied }: { row: DisplayCampaign; isTied: boolean }) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: row.id });
  const navigate = useNavigate();

  return (
    <TableRow
      ref={setNodeRef}
      style={{ transform: CSS.Transform.toString(transform), transition }}
      data-state={isDragging ? 'selected' : undefined}
    >
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
              trigger={
                <Badge variant="outline" className="uppercase">
                  {__('Priority tie', 'yayloyalty')}
                </Badge>
              }
              content={__('Shares this priority with another campaign in this group', 'yayloyalty')}
            />
          )}
          {row.is_builtin && (
            <>
              <Badge variant="outline" className="uppercase">
                {__('Default', 'yayloyalty')}
              </Badge>
              <YayLoyaltyToolTip
                trigger={<Lock className="text-muted-foreground size-3" />}
                content={__('Built-in campaigns cannot be deleted.', 'yayloyalty')}
              />
            </>
          )}
        </div>
      </TableCell>
      <TableCell className="text-muted-foreground text-xs">{triggerLabel(row.trigger)}</TableCell>
      <TableCell className="text-xs">{row.gives}</TableCell>
      <TableCell className="text-muted-foreground text-xs">{row.conditionsLabel}</TableCell>
      <TableCell className="text-muted-foreground text-xs">{row.scheduleLabel}</TableCell>
      <TableCell className="text-muted-foreground text-right text-xs tabular-nums">
        {hasPointsFigure(row.displayStatus) ? <PointsValue value={row.pointsLast30Days ?? 0} /> : '—'}
      </TableCell>
      <TableCell className="text-center text-sm font-semibold tabular-nums">{row.priority}</TableCell>
      <TableCell>
        <Badge variant="outline" className="uppercase">
          {statusLabel(row.displayStatus)}
        </Badge>
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
            {/*
              Opens the same editor as the row link, seeded with a new name and draft status via
              router state — NOT a createCampaign() call. See CampaignEditor.tsx's docblock: with
              create_item() persisting for real against a list still reading a dummy fixture, a
              genuine duplicate would vanish from this table on the very next fetch. Router state
              rather than a query param — it is meant to be gone on reload, since nothing was
              actually duplicated for a reload to restore.
            */}
            <DropdownMenuItem
              onSelect={() =>
                navigate(`/campaigns/${row.id}/edit`, {
                  state: {
                    duplicateSeed: {
                      /* translators: %s: the name of the campaign being duplicated. */
                      name: sprintf(__('%s (copy)', 'yayloyalty'), row.name),
                      status: 'draft' as CampaignStatus,
                    } satisfies CampaignEditorDuplicateSeed,
                  },
                })
              }
            >
              {__('Duplicate', 'yayloyalty')}
            </DropdownMenuItem>
            {/* Inert: no delete flow in this phase — CampaignRestController's delete_item() is real, but this table has no confirm-and-wire step for it yet. */}
            <DropdownMenuItem variant="destructive" disabled={row.is_builtin} onSelect={() => {}}>
              {__('Delete', 'yayloyalty')}
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </TableCell>
    </TableRow>
  );
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

/**
 * The real `Campaign` shape has no `gives`/conditions/schedule label and no
 * derived display status — every one of those is computed here, once, from
 * `config`/`conditions`/`status`/`starts_at`/`ends_at`. Generic across all
 * seven triggers, not special-cased to any one dummy row.
 */
function toDisplayCampaign(campaign: Campaign): DisplayCampaign {
  return {
    ...campaign,
    gives: givesLabel(campaign.trigger, campaign.config),
    conditionsLabel: conditionsLabel(campaign.conditions),
    scheduleLabel: scheduleLabel(campaign.starts_at, campaign.ends_at),
    displayStatus: resolveCampaignDisplayStatus(campaign.status, campaign.starts_at, campaign.ends_at),
    pointsLast30Days: DUMMY_POINTS_LAST_30_DAYS[campaign.id] ?? null,
  };
}

function scheduleLabel(startsAt: string | null, endsAt: string | null): string {
  if (!startsAt && !endsAt) {
    return __('Always on', 'yayloyalty');
  }

  if (startsAt && endsAt) {
    return `${parseWPDate(startsAt)} – ${parseWPDate(endsAt)}`;
  }

  if (startsAt) {
    return sprintf(/* translators: %s: a formatted date. */ __('From %s', 'yayloyalty'), parseWPDate(startsAt));
  }

  return sprintf(/* translators: %s: a formatted date. */ __('Until %s', 'yayloyalty'), parseWPDate(endsAt as string));
}
