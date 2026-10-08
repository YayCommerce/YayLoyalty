import { __, _n, sprintf } from '@wordpress/i18n';

import { formatPoints } from '@/lib/helpers/points.helper';
import type { Reward, RewardRedemptionSummary } from '@/lib/schema/reward.schema';

/** Case resolution and copy for RewardArchiveDialog; same split as campaign-archive.copy.ts. */

/**
 * Which dialog a reward gets: archive once anything names it; delete while
 * nothing does, with "archive instead" offered unless it is already archived.
 */
export type RewardArchiveCase = 'archive' | 'delete' | 'delete-only';

/** The action a dialog button writes; 'archived' is a status PUT, 'deleted' the DELETE route. */
export type RewardArchiveAction = 'archived' | 'deleted';

/**
 * `is_deletable` is the server's own delete_item() guard (not built-in, no
 * redemption row), never inferred here from the summary's figures.
 */
export function resolveRewardArchiveCase(reward: Reward): RewardArchiveCase {
  if (!reward.is_deletable) {
    return 'archive';
  }

  return reward.status === 'archived' ? 'delete-only' : 'delete';
}

/**
 * The archive dialog's body, one sentence per line. The last line carries <link> for
 * createInterpolateElement, pointing at the Archived view.
 */
export function archiveBodyLines(summary: RewardRedemptionSummary): string[] {
  const lines: string[] = [];

  if (summary.outstanding > 0) {
    lines.push(
      sprintf(
        /* translators: %s: number of unused coupons, formatted. */
        _n(
          '%s unused coupon from this reward keeps working until it expires.',
          '%s unused coupons from this reward keep working until they expire.',
          summary.outstanding,
          'yayloyalty',
        ),
        formatPoints(summary.outstanding),
      ),
    );
  }

  lines.push(
    __(
      'Customers can no longer claim it. Past redemptions and customer history are kept. You can restore it from <link>Archived</link>.',
      'yayloyalty',
    ),
  );

  return lines;
}

export function deleteBodyLine(): string {
  return __('No customer has claimed this reward, so it can be deleted. This cannot be undone.', 'yayloyalty');
}

export function successMessage(action: RewardArchiveAction): string {
  return action === 'deleted' ? __('Reward deleted.', 'yayloyalty') : __('Reward archived.', 'yayloyalty');
}

export function failureMessage(action: RewardArchiveAction): string {
  return action === 'deleted'
    ? __('Could not delete the reward.', 'yayloyalty')
    : __('Could not archive the reward.', 'yayloyalty');
}
