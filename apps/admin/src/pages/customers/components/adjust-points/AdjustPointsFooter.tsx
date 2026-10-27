import { __ } from '@wordpress/i18n';

import { formatPoints } from '@/lib/helpers/points.helper';
import { Button, LoadingButton } from '@/components/ui/button';
import { DialogFooter } from '@/components/ui/custom/dialog';
import { bulkSubmitLabel } from '../customers-adjust-points.bulk.copy';
import {
  SUBMIT_HINT_ACKNOWLEDGE,
  SUBMIT_HINT_PREFIX,
  SUBMIT_HINT_REASON,
  submitLabel,
} from '../customers-adjust-points.copy';
import type { AdjustFormState, SubmitBlockerKey } from '../customers-adjust-points.form-state';

/**
 * Steps the admin still has to take, in layout order. Stale, unverified and over-limit
 * have their own banners; a missing amount needs no hint.
 */
const HINTS: [SubmitBlockerKey, () => string][] = [
  ['acknowledge', SUBMIT_HINT_ACKNOWLEDGE],
  ['reason', SUBMIT_HINT_REASON],
];

/** Cancel + the action-restating submit button, single or bulk phrasing, with what still blocks it. */
export function AdjustPointsFooter({
  isSingle,
  state,
  effectiveCount,
  isSubmitting,
  isCheckingBeforeSubmit,
  isPreviewLoading,
  blockers,
  onCancel,
  onSubmit,
}: {
  isSingle: boolean;
  state: Pick<AdjustFormState, 'direction' | 'amount'>;
  /** Bulk only. */
  effectiveCount: number;
  isSubmitting: boolean;
  isCheckingBeforeSubmit: boolean;
  isPreviewLoading: boolean;
  blockers: SubmitBlockerKey[];
  onCancel: () => void;
  onSubmit: () => void;
}) {
  const isBusy = isSubmitting || isCheckingBeforeSubmit;
  const hints = state.amount > 0 ? HINTS.filter(([key]) => blockers.includes(key)).map(([, label]) => label()) : [];

  return (
    <div className="flex flex-col gap-2">
      {hints.length > 0 && (
        <p className="text-muted-foreground text-xs sm:text-right">
          {SUBMIT_HINT_PREFIX()} {hints.join(' · ')}
        </p>
      )}
      <DialogFooter>
        <Button variant="outline" onClick={onCancel} disabled={isBusy}>
          {__('Cancel', 'yayloyalty')}
        </Button>
        <LoadingButton loading={isBusy} onClick={onSubmit} disabled={isBusy || isPreviewLoading || blockers.length > 0}>
          {isSingle
            ? submitLabel(state.direction, formatPoints(state.amount), state.amount === 0)
            : bulkSubmitLabel(state.direction, formatPoints(state.amount), effectiveCount)}
        </LoadingButton>
      </DialogFooter>
    </div>
  );
}
