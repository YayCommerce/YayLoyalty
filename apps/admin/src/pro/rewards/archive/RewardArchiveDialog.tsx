import { ReactElement, useState } from 'react';
import { toast } from 'sonner';
import { createInterpolateElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { toastApiError } from '@/lib/api/api';
import { useDeleteRewardMutation, useUpdateRewardStatusMutation } from '@/lib/queries/reward.queries';
import type { Reward } from '@/lib/schema/reward.schema';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/custom/dialog';
import {
  archiveBodyLines,
  deleteBodyLine,
  failureMessage,
  resolveRewardArchiveCase,
  RewardArchiveAction,
  successMessage,
} from './reward-archive.copy';

/**
 * Archive-or-delete confirmation for one catalog reward. Mount it only while
 * open: each mount is one decision with fresh mutation state.
 */
export function RewardArchiveDialog({
  reward,
  onClose,
  onDone,
  onViewArchived,
  discardsUnsavedChanges = false,
}: {
  reward: Reward;
  onClose: () => void;
  /** Runs after a successful write, once the dialog has closed. */
  onDone?: () => void;
  /** Omitted where there is no Archived view to jump to. */
  onViewArchived?: () => void;
  /** The editor's case: leaving the page drops whatever was not saved. */
  discardsUnsavedChanges?: boolean;
}) {
  const archiveCase = resolveRewardArchiveCase(reward);
  const isDelete = archiveCase !== 'archive';
  const canArchiveInstead = archiveCase === 'delete';

  const statusMutation = useUpdateRewardStatusMutation(reward.id);
  const deleteMutation = useDeleteRewardMutation(reward.id);
  const [pendingAction, setPendingAction] = useState<RewardArchiveAction>();
  const busy = pendingAction !== undefined;

  async function run(action: RewardArchiveAction) {
    setPendingAction(action);

    try {
      if (action === 'deleted') {
        await deleteMutation.mutateAsync();
      } else {
        await statusMutation.mutateAsync('archived');
      }

      toast.success(successMessage(action));
      onClose();
      onDone?.();
    } catch (error) {
      await toastApiError(error, failureMessage(action));
      setPendingAction(undefined);
    }
  }

  const lines = isDelete ? [deleteBodyLine()] : archiveBodyLines(reward.redemption_summary);

  return (
    <Dialog open onOpenChange={(open) => !open && !busy && onClose()}>
      <DialogContent className="gap-0 p-0 sm:max-w-lg" showCloseButton={!busy}>
        <DialogHeader className="p-5 pr-12 pb-0">
          <DialogTitle>
            {isDelete
              ? /* translators: %s: the reward's name. */ sprintf(__('Delete “%s”?', 'yayloyalty'), reward.name)
              : /* translators: %s: the reward's name. */ sprintf(__('Archive “%s”?', 'yayloyalty'), reward.name)}
          </DialogTitle>
        </DialogHeader>

        <DialogDescription asChild>
          <div className="text-foreground flex flex-col gap-2 p-5">
            {lines.map((line) => (
              <p key={line}>{createInterpolateElement(line, { link: archivedLink(busy, onClose, onViewArchived) })}</p>
            ))}
            {discardsUnsavedChanges && (
              <p className="text-muted-foreground text-xs">
                {__('Unsaved changes on this page are discarded.', 'yayloyalty')}
              </p>
            )}
          </div>
        </DialogDescription>

        <DialogFooter className="bg-muted/40 border-t p-5 sm:items-center sm:justify-between">
          <div>
            {canArchiveInstead && (
              <Button variant="outline" disabled={busy} onClick={() => run('archived')}>
                {pendingAction === 'archived' ? __('Archiving…', 'yayloyalty') : __('Archive instead', 'yayloyalty')}
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
                {pendingAction === 'archived' ? __('Archiving…', 'yayloyalty') : __('Archive reward', 'yayloyalty')}
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
