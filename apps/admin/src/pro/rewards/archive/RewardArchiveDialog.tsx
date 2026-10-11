import { __, sprintf } from '@wordpress/i18n';

import { useDeleteRewardMutation, useUpdateRewardStatusMutation } from '@/lib/queries/reward.queries';
import type { Reward } from '@/lib/schema/reward.schema';
import { ArchiveOrDeleteDialog } from '../../shared/ArchiveOrDeleteDialog';
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

  return (
    <ArchiveOrDeleteDialog<RewardArchiveAction>
      title={
        isDelete
          ? /* translators: %s: the reward's name. */ sprintf(__('Delete “%s”?', 'yayloyalty'), reward.name)
          : /* translators: %s: the reward's name. */ sprintf(__('Archive “%s”?', 'yayloyalty'), reward.name)
      }
      lines={isDelete ? [deleteBodyLine()] : archiveBodyLines(reward.redemption_summary)}
      note={
        discardsUnsavedChanges && (
          <p className="text-muted-foreground text-xs">
            {__('Unsaved changes on this page are discarded.', 'yayloyalty')}
          </p>
        )
      }
      primary={
        isDelete
          ? {
              action: 'deleted',
              label: __('Delete permanently', 'yayloyalty'),
              pendingLabel: __('Deleting…', 'yayloyalty'),
            }
          : {
              action: 'archived',
              label: __('Archive reward', 'yayloyalty'),
              pendingLabel: __('Archiving…', 'yayloyalty'),
            }
      }
      secondary={
        canArchiveInstead
          ? {
              action: 'archived',
              label: __('Archive instead', 'yayloyalty'),
              pendingLabel: __('Archiving…', 'yayloyalty'),
            }
          : undefined
      }
      perform={(action) =>
        action === 'deleted' ? deleteMutation.mutateAsync() : statusMutation.mutateAsync('archived')
      }
      successMessage={successMessage}
      failureMessage={failureMessage}
      onClose={onClose}
      onDone={onDone}
      onViewArchived={onViewArchived}
    />
  );
}
