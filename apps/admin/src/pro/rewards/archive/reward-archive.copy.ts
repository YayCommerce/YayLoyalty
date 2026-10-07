import { __, _n, sprintf } from '@wordpress/i18n';

import { formatStoredDate } from '@/lib/helpers/format.helper';
import { formatPoints, pointsPhrase } from '@/lib/helpers/points.helper';
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

/** The line under the dialog title. */
export function contextLine(reward: Reward, archiveCase: RewardArchiveCase): string {
  const { claimed, first_claimed_at: firstClaimedAt } = reward.redemption_summary;

  if (archiveCase !== 'archive') {
    return sprintf(
      /* translators: %s: the date the reward was created. */
      __('Created %s · never claimed', 'yayloyalty'),
      formatStoredDate(reward.created_at),
    );
  }

  const cost =
    reward.cost_points === null
      ? ''
      : sprintf(
          /* translators: %s: points phrase, e.g. "500 points". */
          __('%s each', 'yayloyalty'),
          pointsPhrase(reward.cost_points),
        );

  // first_claimed_at is MIN(created_at) over the claimed rows: null exactly when claimed is 0.
  if (firstClaimedAt === null) {
    return cost;
  }

  const claims = sprintf(
    /* translators: 1: number of claims, formatted; 2: the date of the first claim. */
    _n('%1$s claimed since %2$s', '%1$s claimed since %2$s', claimed, 'yayloyalty'),
    formatPoints(claimed),
    formatStoredDate(firstClaimedAt),
  );

  return cost ? `${claims} · ${cost}` : claims;
}

/** The unused-coupons warning; undefined when no coupon is outstanding, so the card is not rendered. */
export function outstandingWarning(summary: RewardRedemptionSummary): { headline: string; detail: string } | undefined {
  if (summary.outstanding === 0) {
    return undefined;
  }

  const headline = sprintf(
    /* translators: %s: number of customers, formatted. */
    _n(
      '%s customer holds unused coupons from this reward',
      '%s customers hold unused coupons from this reward',
      summary.outstanding_customers,
      'yayloyalty',
    ),
    formatPoints(summary.outstanding_customers),
  );

  const spent = sprintf(
    /* translators: %s: points phrase, e.g. "4,500 points". */
    __('They spent %s and have not used the codes yet.', 'yayloyalty'),
    pointsPhrase(summary.outstanding_points),
  );

  const expiry = summary.last_expires_at
    ? sprintf(
        /* translators: %s: the date the last unused coupon expires. */
        __('Those coupons keep working until they expire — the last one on %s.', 'yayloyalty'),
        formatStoredDate(summary.last_expires_at),
      )
    : __('Those coupons keep working until they expire.', 'yayloyalty');

  return { headline, detail: `${spent} ${expiry}` };
}

/** Archive case, left column: what survives. Counts only appear once there is something to count. */
export function keepsLines(summary: RewardRedemptionSummary): string[] {
  const lines: string[] = [];

  if (summary.outstanding > 0) {
    lines.push(
      sprintf(
        /* translators: %s: number of unused coupons, formatted. */
        _n(
          'The %s unused coupon, until it expires',
          'The %s unused coupons, until they expire',
          summary.outstanding,
          'yayloyalty',
        ),
        formatPoints(summary.outstanding),
      ),
    );
  }

  const recorded = summary.claimed - summary.outstanding;

  if (recorded > 0) {
    lines.push(
      sprintf(
        /* translators: %s: number of redemptions, formatted. */
        _n('%s redemption already recorded', '%s redemptions already recorded', recorded, 'yayloyalty'),
        formatPoints(recorded),
      ),
    );
  }

  lines.push(__('Customer history, still naming this reward', 'yayloyalty'));

  return lines;
}

/** Archive case, right column: what ends. */
export function stopsLines(): string[] {
  return [
    __('Customers can’t claim it again', 'yayloyalty'),
    __('It disappears from the launcher and My Account', 'yayloyalty'),
    __('No new coupons are issued', 'yayloyalty'),
  ];
}

/** Delete case, left column: what is removed. */
export function goesLines(): string[] {
  return [__('The reward and its settings', 'yayloyalty'), __('Its row in the rewards list', 'yayloyalty')];
}

/** Delete case, right column: the zeroes that make deleting safe. */
export function affectedLine(): string {
  return __('Nothing — 0 claims, 0 coupons, 0 customers', 'yayloyalty');
}

export function successMessage(action: RewardArchiveAction): string {
  return action === 'deleted' ? __('Reward deleted.', 'yayloyalty') : __('Reward archived.', 'yayloyalty');
}

export function failureMessage(action: RewardArchiveAction): string {
  return action === 'deleted'
    ? __('Could not delete the reward.', 'yayloyalty')
    : __('Could not archive the reward.', 'yayloyalty');
}
