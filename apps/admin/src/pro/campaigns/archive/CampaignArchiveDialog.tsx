import { useId, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { CheckIcon, CircleAlert, TriangleAlert } from 'lucide-react';
import { toast } from 'sonner';
import { createInterpolateElement } from '@wordpress/element';
import { __, _n, _x, sprintf } from '@wordpress/i18n';

import { getApiErrorMessage, toastApiError } from '@/lib/api/api';
import {
  CampaignBulkWrites,
  useBulkCampaignWritesMutation,
  useCampaignsQuery,
  useDeleteCampaignMutation,
  useUpdateCampaignStatusMutation,
} from '@/lib/queries/campaign.queries';
import { hasPerformancePage } from '@/lib/schema/campaign-performance.schema';
import { Campaign, findBuiltinCampaign, resolveCampaignDisplayStatus } from '@/lib/schema/campaign.schema';
import { cn } from '@/lib/utils';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/custom/dialog';
import { ListColumns } from '@/components/ui/custom/list-columns';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { campaignStatusBadgeVariant } from '../campaign-labels';
import { CAMPAIGN_PERFORMANCE_QUERIES } from '../performance/campaign-performance.queries';
import {
  affectedLine,
  ArchiveFigures,
  contextLine,
  earningFigures,
  finishedFigures,
  goesLines,
  keepsLines,
  lifetimeFigures,
  resolveArchiveCase,
  stopsLines,
} from './campaign-archive.copy';
import {
  BulkArchiveRow,
  bulkContextLine,
  bulkRowBadge,
  bulkRowFigures,
  bulkSummaryLines,
  bulkTotalLine,
} from './campaign-bulk-archive.copy';

export type CampaignArchiveAction = 'archived' | 'paused' | 'deleted';

/**
 * Archive-or-delete confirmation for one non-built-in campaign: the finished, live and
 * never-awarded (delete) cases. Mount it only while open, so each decision gets fresh mutation
 * state. Figures are context, never a gate: a failed performance fetch still lets the merchant archive.
 */
export function CampaignArchiveDialog({
  campaign,
  onClose,
  onDone,
  onViewArchived,
}: {
  campaign: Campaign;
  onClose: () => void;
  /** Runs after a successful write, once the dialog has closed. */
  onDone?: (action: CampaignArchiveAction) => void;
  /** Omitted where there is no Archived view to jump to. */
  onViewArchived?: () => void;
}) {
  const displayStatus = resolveCampaignDisplayStatus(campaign.status, campaign.starts_at, campaign.ends_at);
  const lifetimePoints = campaign.points_issued;
  const archiveCase = resolveArchiveCase(displayStatus, campaign.is_deletable);
  const isEarning = archiveCase === 'live' && displayStatus === 'active';

  const wantsPerformance = (archiveCase === 'finished' || isEarning) && hasPerformancePage(campaign.trigger);
  const performanceQuery = useQuery({
    ...CAMPAIGN_PERFORMANCE_QUERIES.summary(campaign.id),
    enabled: wantsPerformance,
  });

  const campaignsQuery = useCampaignsQuery({ perPage: 100 });
  const fallback = findBuiltinCampaign(campaignsQuery.data?.data, campaign.trigger);

  const statusMutation = useUpdateCampaignStatusMutation(campaign.id);
  const deleteMutation = useDeleteCampaignMutation(campaign.id);
  const [pendingAction, setPendingAction] = useState<CampaignArchiveAction>();
  const busy = pendingAction !== undefined;

  async function run(action: CampaignArchiveAction) {
    setPendingAction(action);

    try {
      if (action === 'deleted') {
        await deleteMutation.mutateAsync();
      } else {
        await statusMutation.mutateAsync(action);
      }

      toast.success(successMessage(action));
      onClose();
      onDone?.(action);
    } catch (error) {
      await toastApiError(error, failureMessage(action));
      setPendingAction(undefined);
    }
  }

  let figures: ArchiveFigures | undefined;

  if (wantsPerformance) {
    if (performanceQuery.data) {
      figures =
        archiveCase === 'finished'
          ? finishedFigures(campaign.trigger, performanceQuery.data)
          : earningFigures(campaign.trigger, performanceQuery.data);
    }
  } else if (archiveCase !== 'delete') {
    figures = isEarning
      ? {
          headline: __('This campaign is live right now', 'yayloyalty'),
          detail: lifetimeFigures(lifetimePoints).headline,
        }
      : lifetimeFigures(lifetimePoints);
  }

  const title =
    archiveCase === 'delete'
      ? sprintf(
          /* translators: %s: the campaign's name. */
          __('Delete “%s”?', 'yayloyalty'),
          campaign.name,
        )
      : sprintf(
          /* translators: %s: the campaign's name. */
          __('Archive “%s”?', 'yayloyalty'),
          campaign.name,
        );

  return (
    <Dialog open onOpenChange={(open) => !open && !busy && onClose()}>
      <DialogContent className="gap-0 p-0 sm:max-w-2xl" showCloseButton={!busy}>
        <DialogHeader className="gap-1 border-b p-5 pr-12">
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{contextLine(campaign, archiveCase, displayStatus)}</DialogDescription>
        </DialogHeader>

        <div className="flex flex-col gap-4 p-5">
          {archiveCase === 'delete' ? (
            <DeleteBody campaign={campaign} busy={busy} onArchiveInstead={() => run('archived')} />
          ) : (
            <>
              {(figures || (wantsPerformance && !performanceQuery.isError)) && (
                <FiguresBlock figures={figures} marked={isEarning} />
              )}
              {wantsPerformance && performanceQuery.isError && (
                <p className="text-muted-foreground text-sm">
                  {__('Figures are unavailable right now. Archiving does not depend on them.', 'yayloyalty')}
                </p>
              )}

              <ListColumns
                leftTitle={__('Keeps working', 'yayloyalty')}
                leftLines={keepsLines(lifetimePoints)}
                rightTitle={__('Stops', 'yayloyalty')}
                right={stopsLines(campaign, displayStatus, fallback)}
              />

              {isEarning && (
                <div className="flex flex-col gap-3 rounded-md border p-4 sm:flex-row sm:items-center">
                  <div className="flex flex-1 flex-col gap-1">
                    <span className="text-sm font-semibold">{__('Pause instead', 'yayloyalty')}</span>
                    <span className="text-muted-foreground text-xs">
                      {__(
                        'Pausing stops it earning but keeps it in the list, with its priority and conditions, ready to switch back on.',
                        'yayloyalty',
                      )}
                    </span>
                  </div>
                  <Button variant="outline" disabled={busy} onClick={() => run('paused')}>
                    {pendingAction === 'paused' ? __('Pausing…', 'yayloyalty') : __('Pause instead', 'yayloyalty')}
                  </Button>
                </div>
              )}

              {archiveCase === 'finished' && (
                <div className="flex flex-col gap-1">
                  <p className="text-sm">
                    {createInterpolateElement(
                      __(
                        'It moves to <b>Archived</b> and leaves the list. You can restore it at any time.',
                        'yayloyalty',
                      ),
                      { b: <strong /> },
                    )}
                  </p>
                  <p className="text-muted-foreground text-xs">
                    {__(
                      'Archiving is a tidying action, not a destructive one. Nothing about a customer’s balance or history changes.',
                      'yayloyalty',
                    )}
                  </p>
                </div>
              )}
            </>
          )}
        </div>

        <DialogFooter className="bg-muted/40 border-t p-5 sm:items-center sm:justify-between">
          {archiveCase === 'delete' ? (
            <>
              <div></div>
              <div className="flex flex-col-reverse gap-2 sm:flex-row">
                {/* Reversed emphasis on purpose: Cancel is the safe default, Delete the plain marked choice. */}
                <Button variant="outline" disabled={busy} onClick={onClose}>
                  {__('Cancel', 'yayloyalty')}
                </Button>
                <Button disabled={busy} onClick={() => run('archived')}>
                  {pendingAction === 'archived' ? __('Archiving…', 'yayloyalty') : __('Archive campaign', 'yayloyalty')}
                </Button>
                <Button disabled={busy} onClick={() => run('deleted')}>
                  {pendingAction === 'deleted' ? __('Deleting…', 'yayloyalty') : __('Delete permanently', 'yayloyalty')}
                </Button>
              </div>
            </>
          ) : (
            <>
              <span className="text-muted-foreground text-xs">
                {createInterpolateElement(__('Restorable from <link>Archived</link>', 'yayloyalty'), {
                  link: onViewArchived ? (
                    <Button
                      variant="link"
                      className="h-auto p-0 text-xs underline"
                      disabled={busy}
                      onClick={() => {
                        onClose();
                        onViewArchived();
                      }}
                    />
                  ) : (
                    <span />
                  ),
                })}
              </span>
              <div className="flex flex-col-reverse gap-2 sm:flex-row">
                <Button variant="outline" disabled={busy} onClick={onClose}>
                  {__('Cancel', 'yayloyalty')}
                </Button>
                <Button disabled={busy} onClick={() => run('archived')}>
                  {pendingAction === 'archived' ? __('Archiving…', 'yayloyalty') : __('Archive campaign', 'yayloyalty')}
                </Button>
              </div>
            </>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/** What the campaign did; `marked` is the live banner, which states a fact rather than a warning. */
function FiguresBlock({ figures, marked }: { figures: ArchiveFigures | undefined; marked: boolean }) {
  if (!figures) {
    return <Skeleton className="h-16 w-full" />;
  }

  return (
    <Alert variant="muted" className={cn('text-foreground', marked && 'border-l-foreground border-l-4')}>
      {marked && <CircleAlert />}
      <AlertTitle className="line-clamp-none font-semibold">{figures.headline}</AlertTitle>
      {figures.detail && <AlertDescription className="text-foreground">{figures.detail}</AlertDescription>}
    </Alert>
  );
}

/** The never-awarded campaign: the only one that may be deleted outright. */
function DeleteBody({
  campaign,
  busy,
  onArchiveInstead,
}: {
  campaign: Campaign;
  busy: boolean;
  onArchiveInstead: () => void;
}) {
  return (
    <>
      <Alert variant="muted" className="text-foreground">
        <AlertTitle className="line-clamp-none text-base font-semibold">
          {__('This campaign has never awarded any points, so it can be deleted permanently', 'yayloyalty')}
        </AlertTitle>
        <AlertDescription className="text-foreground">
          {__('Nothing in customer history refers to it. No ledger row, no report figure, no order.', 'yayloyalty')}
        </AlertDescription>
      </Alert>

      <ListColumns
        leftTitle={__('Goes', 'yayloyalty')}
        leftLines={goesLines(campaign)}
        rightTitle={__('Affected', 'yayloyalty')}
        right={{ lines: [affectedLine(campaign.trigger)], nothing: true }}
      />

      <Alert variant="outline" className="border-l-foreground border-l-4">
        <TriangleAlert />
        <AlertDescription className="text-foreground block">
          {createInterpolateElement(
            __(
              'Deleting cannot be undone. If you might want it again, <link>archive it</link> instead and restore it then.',
              'yayloyalty',
            ),
            {
              link: (
                <Button
                  variant="link"
                  className="text-primary h-auto p-0 align-baseline underline"
                  disabled={busy}
                  onClick={onArchiveInstead}
                />
              ),
            },
          )}
        </AlertDescription>
      </Alert>

      <div className="bg-muted/40 flex flex-col gap-1 rounded-md border p-4">
        <span className="text-sm font-semibold">{__('Why this dialog and not the archive one', 'yayloyalty')}</span>
        <p className="text-muted-foreground text-sm">
          {__(
            'A campaign can be deleted only while no ledger row names it. The moment it awards a single point, delete is gone for good and archive takes over — even if that point is later refunded back to zero.',
            'yayloyalty',
          )}
        </p>
      </div>
    </>
  );
}

function successMessage(action: CampaignArchiveAction): string {
  switch (action) {
    case 'archived':
      return __('Campaign archived.', 'yayloyalty');
    case 'paused':
      return __('Campaign paused.', 'yayloyalty');
    case 'deleted':
      return __('Campaign deleted.', 'yayloyalty');
  }
}

function failureMessage(action: CampaignArchiveAction): string {
  switch (action) {
    case 'archived':
      return __('Could not archive the campaign.', 'yayloyalty');
    case 'paused':
      return __('Could not pause the campaign.', 'yayloyalty');
    case 'deleted':
      return __('Could not delete the campaign.', 'yayloyalty');
  }
}

// Bulk archive

/** Which button the bulk dialog is running: Archive, or "Pause instead" for the live rows. */
type BulkArchiveAction = 'archive' | 'pause';

/**
 * Archive confirmation for two or more non-built-in campaigns: one summary,
 * then the rows split by what can happen to them. Never-awarded rows are
 * archived with the rest unless the merchant opts into deleting them: a bulk
 * action must not delete something because it happened to be selected.
 * Mount it only while open. Any failure, full or partial, keeps it open
 * rather than closing on a result the merchant has not seen.
 */
export function CampaignBulkArchiveDialog({
  campaigns,
  onClose,
  onDone,
  onViewArchived,
}: {
  campaigns: Campaign[];
  onClose: () => void;
  /** Runs after a batch that changed at least one campaign, once the dialog has closed. */
  onDone?: () => void;
  /** Omitted where there is no Archived view to jump to. */
  onViewArchived?: () => void;
}) {
  const deleteOptInId = useId();
  const cancelRef = useRef<HTMLButtonElement>(null);
  const rows = campaigns.map(toBulkArchiveRow);
  const archiveRows = rows.filter((row) => row.archiveCase !== 'delete');
  const deleteRows = rows.filter((row) => row.archiveCase === 'delete');
  const liveCount = rows.filter((row) => row.displayStatus === 'active').length;

  const [deleteNeverAwarded, setDeleteNeverAwarded] = useState(false);
  const archiveWrites = planWrites(rows, deleteNeverAwarded, false);
  const pauseWrites = planWrites(rows, deleteNeverAwarded, true);
  const archiveCount = archiveWrites.statuses.length;
  const deleteCount = archiveWrites.deletes.length;

  const bulkMutation = useBulkCampaignWritesMutation();
  const [pendingAction, setPendingAction] = useState<BulkArchiveAction>();
  const busy = pendingAction !== undefined;

  async function run(action: BulkArchiveAction) {
    const writes = action === 'pause' ? pauseWrites : archiveWrites;
    setPendingAction(action);

    try {
      const { succeeded, failed } = await bulkMutation.mutateAsync(writes);

      if (failed > 0) {
        toast.error(
          succeeded === 0
            ? __('Could not update the selected campaigns.', 'yayloyalty')
            : sprintf(
                /* translators: 1: number of campaigns updated; 2: number of campaigns in the batch. */
                _n(
                  '%1$d of %2$d campaign was updated; the rest failed.',
                  '%1$d of %2$d campaigns were updated; the rest failed.',
                  succeeded + failed,
                  'yayloyalty',
                ),
                succeeded,
                succeeded + failed,
              ),
        );
        // Stays open on any failure so the merchant can see which counts landed and retry or
        // cancel, rather than losing track of what happened to an already-cleared selection.
        setPendingAction(undefined);
        return;
      }

      toast.success(bulkSuccessMessage(writes));
      onClose();
      onDone?.();
    } catch (error) {
      toast.error(await getApiErrorMessage(error, __('Could not update the selected campaigns.', 'yayloyalty')));
      setPendingAction(undefined);
    }
  }

  return (
    <Dialog open onOpenChange={(open) => !open && !busy && onClose()}>
      {/* Header and footer stay put; only the body scrolls once many campaigns are listed. */}
      <DialogContent
        className="max-h-[calc(100dvh-4rem)] grid-rows-[auto_minmax(0,1fr)_auto] gap-0 p-0 sm:max-w-2xl"
        showCloseButton={!busy}
        onOpenAutoFocus={(event) => {
          // The first focusable control here can be the delete opt-in, "Pause instead" or the
          // Archived link, any of which a stray Enter would then trigger; Cancel is never that.
          event.preventDefault();
          cancelRef.current?.focus();
        }}
      >
        <DialogHeader className="gap-1 border-b p-5 pr-12">
          <DialogTitle>
            {sprintf(
              /* translators: %d: number of selected campaigns. */
              _n('Archive %d campaign?', 'Archive %d campaigns?', rows.length, 'yayloyalty'),
              rows.length,
            )}
          </DialogTitle>
          <DialogDescription>{bulkContextLine(rows)}</DialogDescription>
        </DialogHeader>

        <div className="flex flex-col gap-5 overflow-y-auto p-5">
          <Alert variant="muted" className="text-foreground border-l-foreground border-l-4">
            <CircleAlert />
            <AlertTitle className="line-clamp-none font-semibold">
              {__('What this changes, in total', 'yayloyalty')}
            </AlertTitle>
            <AlertDescription className="text-foreground gap-2">
              {bulkSummaryLines(rows).map((line) => (
                <p key={line}>{createInterpolateElement(line, { b: <strong /> })}</p>
              ))}
              <p className="border-foreground/20 mt-1 w-full border-t pt-2">{bulkTotalLine(rows)}</p>
            </AlertDescription>
          </Alert>

          {archiveRows.length > 0 && (
            <BulkArchiveGroup
              heading={sprintf(
                /* translators: %d: number of selected campaigns that have awarded points. */
                _n('%d will be archived', '%d will be archived', archiveRows.length, 'yayloyalty'),
                archiveRows.length,
              )}
              caption={_n(
                'it may have paid out, so its history has to keep resolving',
                'they may have paid out, so their history has to keep resolving',
                archiveRows.length,
                'yayloyalty',
              )}
              rows={archiveRows}
            />
          )}

          {deleteRows.length > 0 && (
            <div className="flex flex-col gap-3">
              <BulkArchiveGroup
                heading={sprintf(
                  /* translators: %d: number of selected campaigns that never awarded a point. */
                  _n('%d can be deleted outright', '%d can be deleted outright', deleteRows.length, 'yayloyalty'),
                  deleteRows.length,
                )}
                caption={_n(
                  'never awarded a point, so nothing refers to it',
                  'never awarded a point, so nothing refers to them',
                  deleteRows.length,
                  'yayloyalty',
                )}
                rows={deleteRows}
              />
              <div className="flex items-center gap-3 rounded-md border p-3">
                <Checkbox
                  id={deleteOptInId}
                  checked={deleteNeverAwarded}
                  disabled={busy}
                  onCheckedChange={(checked) => setDeleteNeverAwarded(checked === true)}
                />
                <Label htmlFor={deleteOptInId} className="leading-normal font-normal">
                  {_n(
                    'Delete it instead of archiving — permanent, and it disappears from the archive too',
                    'Delete them instead of archiving — permanent, and they disappear from the archive too',
                    deleteRows.length,
                    'yayloyalty',
                  )}
                </Label>
              </div>
            </div>
          )}

          {liveCount > 0 && (
            <div className="flex flex-col gap-3 rounded-md border p-4 sm:flex-row sm:items-center">
              <div className="flex flex-1 flex-col gap-1">
                <span className="text-sm font-semibold">
                  {sprintf(
                    /* translators: %d: number of selected campaigns that are live right now. */
                    _n('Pause the %d live one instead', 'Pause the %d live ones instead', liveCount, 'yayloyalty'),
                    liveCount,
                  )}
                </span>
                <span className="text-muted-foreground text-xs">
                  {pauseInsteadDetail(liveCount, rows.length - liveCount, pauseWrites.deletes.length)}
                </span>
              </div>
              <Button variant="outline" disabled={busy} onClick={() => run('pause')}>
                {pendingAction === 'pause' ? __('Pausing…', 'yayloyalty') : __('Pause instead', 'yayloyalty')}
              </Button>
            </div>
          )}
        </div>

        <DialogFooter className="bg-muted/40 border-t p-5 sm:items-center sm:justify-between">
          <span className="text-muted-foreground text-xs">
            {deleteCount > 0
              ? __('Deleting cannot be undone', 'yayloyalty')
              : createInterpolateElement(__('Restorable from <link>Archived</link>', 'yayloyalty'), {
                  link: onViewArchived ? (
                    <Button
                      variant="link"
                      className="h-auto p-0 text-xs underline"
                      disabled={busy}
                      onClick={() => {
                        onClose();
                        onViewArchived();
                      }}
                    />
                  ) : (
                    <span />
                  ),
                })}
          </span>
          <div className="flex flex-col-reverse gap-2 sm:flex-row">
            <Button ref={cancelRef} variant="outline" disabled={busy} onClick={onClose}>
              {__('Cancel', 'yayloyalty')}
            </Button>
            <Button disabled={busy} onClick={() => run('archive')}>
              {pendingAction === 'archive' ? pendingLabel(archiveCount) : actionLabel(archiveCount, deleteCount)}
            </Button>
          </div>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/** One capability group: rows listed read-only, since the selection is made in the table. */
function BulkArchiveGroup({ heading, caption, rows }: { heading: string; caption: string; rows: BulkArchiveRow[] }) {
  return (
    <section className="flex flex-col">
      <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
        <h3 className="text-sm font-semibold">{heading}</h3>
        <span className="text-muted-foreground text-xs">{caption}</span>
      </div>
      <ul className="divide-y">
        {rows.map((row) => (
          <li key={row.campaign.id} className="flex items-start gap-3 py-3">
            <CheckIcon className="text-primary mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <div className="flex min-w-0 flex-1 flex-col gap-1.5 sm:flex-row sm:items-start sm:gap-3">
              <span className="text-sm font-medium sm:w-44 sm:shrink-0">{row.campaign.name}</span>
              <span className="sm:w-24 sm:shrink-0">
                <Badge variant={campaignStatusBadgeVariant(row.displayStatus)} className="uppercase">
                  {bulkRowBadge(row.displayStatus)}
                </Badge>
              </span>
              <span className="text-muted-foreground text-sm">{bulkRowFigures(row)}</span>
            </div>
          </li>
        ))}
      </ul>
    </section>
  );
}

function toBulkArchiveRow(campaign: Campaign): BulkArchiveRow {
  const displayStatus = resolveCampaignDisplayStatus(campaign.status, campaign.starts_at, campaign.ends_at);

  return {
    campaign,
    displayStatus,
    archiveCase: resolveArchiveCase(displayStatus, campaign.is_deletable),
    lifetimePoints: campaign.points_issued,
  };
}

/**
 * Every row is archived, or deleted when it never awarded and the merchant
 * opted in; `pauseLive` pauses the live rows instead, as "Pause instead" does.
 */
function planWrites(rows: BulkArchiveRow[], deleteNeverAwarded: boolean, pauseLive: boolean): CampaignBulkWrites {
  const writes: CampaignBulkWrites = { statuses: [], deletes: [] };

  for (const row of rows) {
    if (pauseLive && row.displayStatus === 'active') {
      writes.statuses.push({ id: row.campaign.id, status: 'paused' });
    } else if (deleteNeverAwarded && row.archiveCase === 'delete') {
      writes.deletes.push(row.campaign.id);
    } else {
      writes.statuses.push({ id: row.campaign.id, status: 'archived' });
    }
  }

  return writes;
}

function pauseInsteadDetail(liveCount: number, restCount: number, restDeletes: number): string {
  if (restCount === 0) {
    return _n(
      'Keeps it in the list with its priority and conditions, ready to switch back on.',
      'Keeps them in the list with their priority and conditions, ready to switch back on.',
      liveCount,
      'yayloyalty',
    );
  }

  if (restDeletes > 0) {
    return _n(
      'Keeps it in the list with its priority and conditions, and archives or deletes the rest as chosen above.',
      'Keeps them in the list with their priority and conditions, and archives or deletes the rest as chosen above.',
      liveCount,
      'yayloyalty',
    );
  }

  return _n(
    'Keeps it in the list with its priority and conditions, and archives only the rest.',
    'Keeps them in the list with their priority and conditions, and archives only the rest.',
    liveCount,
    'yayloyalty',
  );
}

function actionLabel(archiveCount: number, deleteCount: number): string {
  if (deleteCount === 0) {
    return sprintf(
      /* translators: %d: number of campaigns to archive. */
      _n('Archive %d campaign', 'Archive %d campaigns', archiveCount, 'yayloyalty'),
      archiveCount,
    );
  }

  if (archiveCount === 0) {
    return sprintf(
      /* translators: %d: number of campaigns to delete. */
      _n('Delete %d campaign', 'Delete %d campaigns', deleteCount, 'yayloyalty'),
      deleteCount,
    );
  }

  return sprintf(
    /* translators: 1: number of campaigns to archive; 2: number of campaigns to delete. */
    __('Archive %1$d, delete %2$d', 'yayloyalty'),
    archiveCount,
    deleteCount,
  );
}

function pendingLabel(archiveCount: number): string {
  return archiveCount === 0 ? __('Deleting…', 'yayloyalty') : __('Archiving…', 'yayloyalty');
}

function bulkSuccessMessage({ statuses, deletes }: CampaignBulkWrites): string {
  const archived = statuses.filter((write) => write.status === 'archived').length;
  const paused = statuses.filter((write) => write.status === 'paused').length;
  const sentences: string[] = [];

  if (archived > 0) {
    sentences.push(
      sprintf(
        /* translators: %d: number of campaigns archived. */
        _n('%d campaign archived.', '%d campaigns archived.', archived, 'yayloyalty'),
        archived,
      ),
    );
  }

  if (paused > 0) {
    sentences.push(
      sprintf(
        /* translators: %d: number of campaigns paused. */
        _n('%d campaign paused.', '%d campaigns paused.', paused, 'yayloyalty'),
        paused,
      ),
    );
  }

  if (deletes.length > 0) {
    sentences.push(
      sprintf(
        /* translators: %d: number of campaigns deleted. */
        _n('%d campaign deleted.', '%d campaigns deleted.', deletes.length, 'yayloyalty'),
        deletes.length,
      ),
    );
  }

  return sentences.join(_x(' ', 'joins two short result sentences', 'yayloyalty'));
}
