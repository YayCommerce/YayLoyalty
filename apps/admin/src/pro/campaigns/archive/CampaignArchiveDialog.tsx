import { ReactElement, useId, useRef, useState } from 'react';
import { toast } from 'sonner';
import { createInterpolateElement } from '@wordpress/element';
import { __, _n, _x, sprintf } from '@wordpress/i18n';

import { getApiErrorMessage, toastApiError } from '@/lib/api/api';
import {
  CampaignBulkWrites,
  useBulkCampaignWritesMutation,
  useDeleteCampaignMutation,
  useUpdateCampaignStatusMutation,
} from '@/lib/queries/campaign.queries';
import { Campaign, resolveCampaignDisplayStatus } from '@/lib/schema/campaign.schema';
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
import { Label } from '@/components/ui/label';
import { archiveBodyLines, resolveArchiveCase } from './campaign-archive.copy';
import { BulkArchiveRow, bulkBodyLines } from './campaign-bulk-archive.copy';

export type CampaignArchiveAction = 'archived' | 'paused' | 'deleted';

/**
 * Archive-or-delete confirmation for one non-built-in campaign; a never-awarded campaign gets
 * delete instead. Mount it only while open, so each decision gets fresh mutation state.
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
  const archiveCase = resolveArchiveCase(displayStatus, campaign.is_deletable);
  const isEarning = archiveCase === 'live' && displayStatus === 'active';

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

  const isDelete = archiveCase === 'delete';
  const lines = isDelete
    ? [__('This campaign has never awarded points, so it can be deleted. This cannot be undone.', 'yayloyalty')]
    : archiveBodyLines(campaign, displayStatus);

  return (
    <Dialog open onOpenChange={(open) => !open && !busy && onClose()}>
      <DialogContent className="gap-0 p-0 sm:max-w-lg" showCloseButton={!busy}>
        <DialogHeader className="p-5 pr-12 pb-0">
          <DialogTitle>
            {isDelete
              ? sprintf(
                  /* translators: %s: the campaign's name. */
                  __('Delete “%s”?', 'yayloyalty'),
                  campaign.name,
                )
              : sprintf(
                  /* translators: %s: the campaign's name. */
                  __('Archive “%s”?', 'yayloyalty'),
                  campaign.name,
                )}
          </DialogTitle>
        </DialogHeader>

        <DialogDescription asChild>
          <div className="text-foreground flex flex-col gap-2 p-5">
            {lines.map((line) => (
              <p key={line}>{createInterpolateElement(line, { link: archivedLink(busy, onClose, onViewArchived) })}</p>
            ))}
          </div>
        </DialogDescription>

        <DialogFooter className="bg-muted/40 border-t p-5 sm:items-center sm:justify-between">
          <div>
            {isEarning && (
              <Button variant="outline" disabled={busy} onClick={() => run('paused')}>
                {pendingAction === 'paused' ? __('Pausing…', 'yayloyalty') : __('Pause instead', 'yayloyalty')}
              </Button>
            )}
          </div>
          <div className="flex flex-col-reverse gap-2 sm:flex-row">
            <Button variant="outline" disabled={busy} onClick={onClose}>
              {__('Cancel', 'yayloyalty')}
            </Button>
            {isDelete ? (
              <Button variant="destructive" disabled={busy} onClick={() => run('deleted')}>
                {pendingAction === 'deleted' ? __('Deleting…', 'yayloyalty') : __('Delete permanently', 'yayloyalty')}
              </Button>
            ) : (
              <Button variant="destructive" disabled={busy} onClick={() => run('archived')}>
                {pendingAction === 'archived' ? __('Archiving…', 'yayloyalty') : __('Archive campaign', 'yayloyalty')}
              </Button>
            )}
          </div>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/** The <link> element in the body copy: jumps to the Archived view, or plain text where there is none. */
function archivedLink(busy: boolean, onClose: () => void, onViewArchived?: () => void): ReactElement {
  if (!onViewArchived) {
    return <span />;
  }

  return (
    <Button
      variant="link"
      className="h-auto p-0 align-baseline underline"
      disabled={busy}
      onClick={() => {
        onClose();
        onViewArchived();
      }}
    />
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
 * Archive confirmation for two or more non-built-in campaigns. Never-awarded
 * rows are archived with the rest unless the merchant opts into deleting them:
 * a bulk action must not delete something because it happened to be selected.
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
  const deleteRowCount = rows.filter((row) => row.archiveCase === 'delete').length;
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
      <DialogContent
        className="gap-0 p-0 sm:max-w-lg"
        showCloseButton={!busy}
        onOpenAutoFocus={(event) => {
          // The first focusable control here can be the Archived link, the delete opt-in or
          // "Pause instead", any of which a stray Enter would then trigger; Cancel is never that.
          event.preventDefault();
          cancelRef.current?.focus();
        }}
      >
        <DialogHeader className="p-5 pr-12 pb-0">
          <DialogTitle>
            {sprintf(
              /* translators: %d: number of selected campaigns. */
              _n('Archive %d campaign?', 'Archive %d campaigns?', rows.length, 'yayloyalty'),
              rows.length,
            )}
          </DialogTitle>
        </DialogHeader>

        <div className="flex flex-col gap-4 p-5">
          <DialogDescription asChild>
            <div className="text-foreground flex flex-col gap-2">
              {bulkBodyLines(liveCount).map((line) => (
                <p key={line}>
                  {createInterpolateElement(line, { link: archivedLink(busy, onClose, onViewArchived) })}
                </p>
              ))}
            </div>
          </DialogDescription>

          {deleteRowCount > 0 && (
            <div className="flex items-center gap-3">
              <Checkbox
                id={deleteOptInId}
                checked={deleteNeverAwarded}
                disabled={busy}
                onCheckedChange={(checked) => setDeleteNeverAwarded(checked === true)}
              />
              <Label htmlFor={deleteOptInId} className="leading-normal font-normal">
                {sprintf(
                  /* translators: %d: number of selected campaigns that never awarded a point. */
                  _n(
                    'Delete the %d that never awarded points instead (permanent)',
                    'Delete the %d that never awarded points instead (permanent)',
                    deleteRowCount,
                    'yayloyalty',
                  ),
                  deleteRowCount,
                )}
              </Label>
            </div>
          )}
        </div>

        <DialogFooter className="bg-muted/40 border-t p-5 sm:items-center sm:justify-between">
          <div>
            {liveCount > 0 && (
              <Button variant="outline" disabled={busy} onClick={() => run('pause')}>
                {pendingAction === 'pause'
                  ? __('Pausing…', 'yayloyalty')
                  : sprintf(
                      /* translators: %d: number of selected campaigns that are live right now. */
                      _n('Pause %d live instead', 'Pause %d live instead', liveCount, 'yayloyalty'),
                      liveCount,
                    )}
              </Button>
            )}
          </div>
          <div className="flex flex-col-reverse gap-2 sm:flex-row">
            <Button ref={cancelRef} variant="outline" disabled={busy} onClick={onClose}>
              {__('Cancel', 'yayloyalty')}
            </Button>
            <Button variant="destructive" disabled={busy} onClick={() => run('archive')}>
              {pendingAction === 'archive' ? pendingLabel(archiveCount) : actionLabel(archiveCount, deleteCount)}
            </Button>
          </div>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function toBulkArchiveRow(campaign: Campaign): BulkArchiveRow {
  const displayStatus = resolveCampaignDisplayStatus(campaign.status, campaign.starts_at, campaign.ends_at);

  return {
    campaign,
    displayStatus,
    archiveCase: resolveArchiveCase(displayStatus, campaign.is_deletable),
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
