import { useState } from 'react';
import { closestCenter, DndContext, PointerSensor, useSensor, useSensors, type DragEndEvent } from '@dnd-kit/core';
import { restrictToVerticalAxis } from '@dnd-kit/modifiers';
import { arrayMove, SortableContext, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { GripVertical, InfoIcon, Lock, Search } from 'lucide-react';
import { __, sprintf } from '@wordpress/i18n';

import type { CampaignTrigger } from '@/lib/schema/campaign.schema';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
import { Empty, EmptyDescription, EmptyHeader, EmptyTitle } from '@/components/ui/empty';
import { InputGroup, InputGroupAddon, InputGroupInput } from '@/components/ui/input-group';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import EllipsisIcon from '@/components/icons/EllipsisIcon';
import {
  CAMPAIGN_DISPLAY_STATUSES,
  formatPoints,
  formatPointsExact,
  isPointsAbbreviated,
  type CampaignDisplayStatus,
  type CampaignTableRow,
} from './campaign-table.api';
import { useCampaignTableQuery } from './campaign-table.queries';
import { CampaignPointsSummary } from './CampaignPointsSummary';

/** Groups larger than this collapse to the default plus this many, highest-priority first. */
const VISIBLE_NON_DEFAULT_ROWS = 4;

/** Above this many campaigns, finding one by scrolling stops being reasonable. */
const SEARCH_THRESHOLD = 20;

/** Table columns, for colSpan on rows that span the whole width. */
const COLUMN_COUNT = 10;

/** A campaign only ever competes with others sharing its group — see triggerGroup(). */
type CampaignGroupKey = 'purchase' | 'signup' | 'review' | 'other';

const GROUP_ORDER: readonly CampaignGroupKey[] = ['purchase', 'signup', 'review', 'other'];

const GROUP_LABELS: Record<CampaignGroupKey, string> = {
  purchase: __('Purchase', 'yayloyalty'),
  signup: __('Signup', 'yayloyalty'),
  review: __('Review', 'yayloyalty'),
  other: __('Other', 'yayloyalty'),
};

/**
 * The three built-ins a fresh install ships with, and nothing else — see
 * "Campaigns — brand new store" in the wireframe. Client-side rather than a
 * second dummy endpoint: `newStore` is a dev-only preview toggle, not a
 * screen state the server needs to know about.
 *
 * name/slug copied from Installer::seed_builtins() — same constraint as
 * built-in-campaign-list.api.ts's BUILT_IN_CAMPAIGNS, and for the same
 * reason: these represent the identical three rows Lite shows before an
 * upgrade, matched by slug.
 */
const NEW_STORE_ROWS: CampaignTableRow[] = [
  {
    id: 1,
    name: 'Points for purchases',
    slug: 'default-purchase',
    trigger: 'purchase',
    gives: '1 point per $1',
    conditions_label: 'No conditions',
    schedule_label: 'Always on',
    priority: 0,
    display_status: 'active',
    is_builtin: true,
    points_last_30_days: null,
  },
  {
    id: 2,
    name: 'Points for signing up',
    slug: 'signup',
    trigger: 'signup',
    gives: '100 points',
    conditions_label: 'Once per customer',
    schedule_label: 'Always on',
    priority: 0,
    display_status: 'active',
    is_builtin: true,
    points_last_30_days: null,
  },
  {
    id: 3,
    name: 'Points for writing a review',
    slug: 'review',
    trigger: 'review',
    gives: '50 points',
    conditions_label: 'Verified purchases only',
    schedule_label: 'Always on',
    priority: 0,
    display_status: 'active',
    is_builtin: true,
    points_last_30_days: null,
  },
];

/**
 * Pro's grouped, prioritised campaigns table — deleted from the Lite build
 * with the rest of `src/pro/`. Lite keeps its own flat, real list in
 * CampaignsPage (a real feature, not gated behind an upgrade overlay).
 *
 * UI ONLY: fetched from a dummy endpoint — see campaign-table.api.ts's SWAP
 * POINT. Every interaction is local state; nothing persists.
 *
 * Drag-to-reorder changes display order only, never `priority` (a separate
 * stored value) — scoped per group, since priority only ever compares
 * campaigns sharing a trigger.
 *
 * `newStore` and `noLiveCampaign` are dev-only preview toggles: flip the
 * defaults below, or edit live via React DevTools. No UI control, on purpose.
 *
 * `newStore` swaps in a whole second fixture (NEW_STORE_ROWS) rather than
 * being a derived flag, because no real signal for it exists yet. It is NOT
 * "every row's `points_last_30_days` is null" — that's a rolling window, so
 * a six-month-old store that simply had no orders this month would falsely
 * read as new. The real thing is store-wide and lifetime: has this account
 * ever recorded an earn ledger entry (LedgerRepository has no such query
 * today; sum_points_for_user() is per-customer, not store-wide). Once that
 * exists, `newStore` becomes `!hasEverEarnedPoints` and `rows` stays a single
 * `useCampaignTableQuery()` call either way — no second fetch.
 */
export function CampaignTable() {
  const [newStore] = useState(false);
  const [noLiveCampaign] = useState(false);

  const { data, isPending, isError } = useCampaignTableQuery(!newStore);

  const [statusFilter, setStatusFilter] = useState<CampaignDisplayStatus | undefined>();
  const [searchQuery, setSearchQuery] = useState('');
  // Local override of fetched order/priority once a drag reorders a group. Seeded lazily from
  // `data` rather than via an effect — see reorderGroup().
  const [localRows, setLocalRows] = useState<CampaignTableRow[]>();

  const baseRows = newStore ? NEW_STORE_ROWS : (localRows ?? data ?? []);
  const rows = noLiveCampaign ? baseRows.map(pauseIfActivePurchase) : baseRows;
  const hasActivePurchaseCampaign = rows.some(
    (row) => triggerGroup(row.trigger) === 'purchase' && row.display_status === 'active',
  );
  const showSearch = rows.length > SEARCH_THRESHOLD;
  // Archived is retired, not merely another status: it stays out of the
  // table entirely unless a merchant asks for it by name. Any other filter
  // (including no filter at all) already excludes it via the equality check.
  const statusFilteredRows = statusFilter
    ? rows.filter((row) => row.display_status === statusFilter)
    : rows.filter((row) => row.display_status !== 'archived');
  const search = searchQuery.trim().toLowerCase();
  const filteredRows =
    showSearch && search
      ? statusFilteredRows.filter((row) => row.name.toLowerCase().includes(search))
      : statusFilteredRows;

  const showLoading = !newStore && isPending;
  const showError = !newStore && isError;

  const groups = GROUP_ORDER.map((key) => ({
    key,
    rows: filteredRows.filter((row) => triggerGroup(row.trigger) === key),
  })).filter((group) => group.rows.length > 0);

  function reorderGroup(groupRows: CampaignTableRow[], event: DragEndEvent) {
    const { active, over } = event;

    if (!over || active.id === over.id) {
      return;
    }

    const fromIndex = groupRows.findIndex((row) => row.id === active.id);
    const toIndex = groupRows.findIndex((row) => row.id === over.id);

    if (fromIndex === -1 || toIndex === -1) {
      return;
    }

    // Display order only. `priority` is a separate, stored value set in the
    // campaign editor — dragging never reads or writes it, it only changes
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
            <p className="text-muted-foreground flex-1 text-sm">
              {newStore
                ? __('Three ways customers earn points. Nothing has been awarded yet.', 'yayloyalty')
                : sprintf(
                    /* translators: %d: total number of campaigns, archived ones included. */
                    __('%d campaigns. Drag to reorder within a group.', 'yayloyalty'),
                    rows.length,
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

            {/* Inert: this screen has no editor to hand a new campaign off to yet. */}
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
                  {/* Inert: no editor and no write endpoint back this screen yet. */}
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

          {showLoading && <Skeleton className="h-64 w-full" />}

          {showError && <p className="text-destructive text-sm">{__('Could not load campaigns.', 'yayloyalty')}</p>}

          {!showLoading && !showError && groups.length === 0 && (
            <Empty>
              <EmptyHeader>
                <EmptyTitle>{__('No campaigns match this filter', 'yayloyalty')}</EmptyTitle>
                <EmptyDescription>{__('Try a different status.', 'yayloyalty')}</EmptyDescription>
              </EmptyHeader>
            </Empty>
          )}

          {!showLoading && !showError && groups.length > 0 && (
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

      {!showLoading && !showError && <CampaignPointsSummary rows={rows} />}
    </>
  );
}

function CampaignGroupSection({
  group,
  onReorder,
}: {
  group: { key: CampaignGroupKey; rows: CampaignTableRow[] };
  onReorder: (groupRows: CampaignTableRow[], event: DragEndEvent) => void;
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
function SortableCampaignRow({ row, isTied }: { row: CampaignTableRow; isTied: boolean }) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: row.id });

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
          {/* Inert: the SPA has no campaign editor yet — see ConditionsPreview's docblock. */}
          <Button variant="link" size="sm" className="h-auto p-0 font-semibold">
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
      <TableCell className="text-muted-foreground text-xs">{row.conditions_label}</TableCell>
      <TableCell className="text-muted-foreground text-xs">{row.schedule_label}</TableCell>
      <TableCell className="text-muted-foreground text-right text-xs tabular-nums">
        {isPointsAbbreviated(row.points_last_30_days, row.display_status) ? (
          <YayLoyaltyToolTip
            trigger={<span>{formatPoints(row.points_last_30_days, row.display_status)}</span>}
            content={formatPointsExact(row.points_last_30_days, row.display_status)}
          />
        ) : (
          formatPoints(row.points_last_30_days, row.display_status)
        )}
      </TableCell>
      <TableCell className="text-center text-sm font-semibold tabular-nums">{row.priority}</TableCell>
      <TableCell>
        <Badge variant="outline" className="uppercase">
          {statusLabel(row.display_status)}
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
            {/* Inert: no editor and no write endpoint back this screen yet. */}
            <DropdownMenuItem onSelect={() => {}}>{__('Duplicate', 'yayloyalty')}</DropdownMenuItem>
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
function pauseIfActivePurchase(row: CampaignTableRow): CampaignTableRow {
  if (triggerGroup(row.trigger) === 'purchase' && row.display_status === 'active') {
    return { ...row, display_status: 'paused' };
  }

  return row;
}

/**
 * Campaigns only compete with others sharing a group. `purchase`, `signup`
 * and `review` each get their own; every other trigger — birthday and the
 * remaining Pro triggers — is rare enough today to share a catch-all rather
 * than earn a group of one each.
 */
function triggerGroup(trigger: CampaignTrigger): CampaignGroupKey {
  if (trigger === 'purchase' || trigger === 'signup' || trigger === 'review') {
    return trigger;
  }

  return 'other';
}

function triggerLabel(trigger: CampaignTrigger): string {
  switch (trigger) {
    case 'purchase':
      return __('Order placed', 'yayloyalty');
    case 'signup':
      return __('Account created', 'yayloyalty');
    case 'review':
      return __('Review published', 'yayloyalty');
    case 'birthday':
      return __('Birthday', 'yayloyalty');
    case 'first_order':
      return __('First order', 'yayloyalty');
    case 'order_count':
      return __('Order count reached', 'yayloyalty');
    case 'spend_milestone':
      return __('Spend milestone reached', 'yayloyalty');
    default:
      return trigger;
  }
}

function statusLabel(status: CampaignDisplayStatus): string {
  switch (status) {
    case 'active':
      return __('Active', 'yayloyalty');
    case 'scheduled':
      return __('Scheduled', 'yayloyalty');
    case 'paused':
      return __('Paused', 'yayloyalty');
    case 'draft':
      return __('Draft', 'yayloyalty');
    case 'archived':
      return __('Archived', 'yayloyalty');
  }
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
