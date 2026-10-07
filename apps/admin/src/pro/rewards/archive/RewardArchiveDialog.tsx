import { useState } from 'react';
import { CircleAlert, TriangleAlert } from 'lucide-react';
import { toast } from 'sonner';
import { createInterpolateElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { toastApiError } from '@/lib/api/api';
import { useDeleteRewardMutation, useUpdateRewardStatusMutation } from '@/lib/queries/reward.queries';
import type { Reward } from '@/lib/schema/reward.schema';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/custom/dialog';
import { ListColumns } from '@/components/ui/custom/list-columns';
import {
  affectedLine,
  contextLine,
  failureMessage,
  goesLines,
  keepsLines,
  outstandingWarning,
  resolveRewardArchiveCase,
  RewardArchiveAction,
  stopsLines,
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
  const isDeleteCase = archiveCase !== 'archive';
  const canArchiveInstead = archiveCase === 'delete';
  const warning = outstandingWarning(reward.redemption_summary);

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

  const title = isDeleteCase
    ? /* translators: %s: the reward's name. */ sprintf(__('Delete “%s”?', 'yayloyalty'), reward.name)
    : /* translators: %s: the reward's name. */ sprintf(__('Archive “%s”?', 'yayloyalty'), reward.name);

  return (
    <Dialog open onOpenChange={(open) => !open && !busy && onClose()}>
      <DialogContent className="gap-0 p-0 sm:max-w-2xl" showCloseButton={!busy}>
        <DialogHeader className="gap-1 border-b p-5 pr-12">
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{contextLine(reward, archiveCase)}</DialogDescription>
        </DialogHeader>

        <div className="flex flex-col gap-4 p-5">
          {isDeleteCase ? (
            <>
              <Alert variant="muted" className="text-foreground">
                <AlertTitle className="line-clamp-none text-base font-semibold">
                  {__('No customer has claimed this reward, so it can be deleted permanently', 'yayloyalty')}
                </AlertTitle>
                <AlertDescription className="text-foreground">
                  {__('Nothing in customer history refers to it. No claim, no coupon, no ledger row.', 'yayloyalty')}
                </AlertDescription>
              </Alert>

              <ListColumns
                leftTitle={__('Goes', 'yayloyalty')}
                leftLines={goesLines()}
                rightTitle={__('Affected', 'yayloyalty')}
                right={{ lines: [affectedLine()], nothing: true }}
              />

              <Alert variant="outline" className="border-l-foreground border-l-4">
                <TriangleAlert />
                <AlertDescription className="text-foreground block">
                  {canArchiveInstead
                    ? createInterpolateElement(
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
                              onClick={() => run('archived')}
                            />
                          ),
                        },
                      )
                    : __('Deleting cannot be undone.', 'yayloyalty')}
                </AlertDescription>
              </Alert>
            </>
          ) : (
            <>
              {warning && (
                <Alert variant="muted" className="text-foreground border-l-foreground border-l-4">
                  <CircleAlert />
                  <AlertTitle className="line-clamp-none font-semibold">{warning.headline}</AlertTitle>
                  <AlertDescription className="text-foreground">{warning.detail}</AlertDescription>
                </Alert>
              )}

              <ListColumns
                leftTitle={__('Keeps working', 'yayloyalty')}
                leftLines={keepsLines(reward.redemption_summary)}
                rightTitle={__('Stops', 'yayloyalty')}
                right={{ lines: stopsLines() }}
              />
            </>
          )}

          {discardsUnsavedChanges && (
            <p className="text-muted-foreground text-xs">
              {__('Unsaved changes on this page are discarded.', 'yayloyalty')}
            </p>
          )}
        </div>

        <DialogFooter className="bg-muted/40 border-t p-5 sm:items-center sm:justify-between">
          {isDeleteCase ? (
            <>
              <div></div>
              <div className="flex flex-col-reverse gap-2 sm:flex-row">
                <Button variant="outline" disabled={busy} onClick={onClose}>
                  {__('Cancel', 'yayloyalty')}
                </Button>
                {canArchiveInstead && (
                  <Button disabled={busy} onClick={() => run('archived')}>
                    {pendingAction === 'archived' ? __('Archiving…', 'yayloyalty') : __('Archive reward', 'yayloyalty')}
                  </Button>
                )}
                <Button variant="destructive" disabled={busy} onClick={() => run('deleted')}>
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
                  {pendingAction === 'archived' ? __('Archiving…', 'yayloyalty') : __('Archive', 'yayloyalty')}
                </Button>
              </div>
            </>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
