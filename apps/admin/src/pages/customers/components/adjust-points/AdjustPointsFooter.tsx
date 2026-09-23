import { __ } from '@wordpress/i18n';

import { formatPoints } from '@/lib/helpers/points.helper';
import { Button } from '@/components/ui/button';
import { DialogFooter } from '@/components/ui/custom/dialog';
import { bulkSubmitLabel } from '../customers-adjust-points.bulk.copy';
import { submitLabel } from '../customers-adjust-points.copy';
import type { AdjustFormState } from '../customers-adjust-points.form-state';

/** Cancel + the action-restating submit button, single or bulk phrasing. */
export function AdjustPointsFooter({
  isSingle,
  state,
  effectiveCount,
  isSubmitting,
  isCheckingBeforeSubmit,
  isPreviewLoading,
  submitEnabled,
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
  submitEnabled: boolean;
  onCancel: () => void;
  onSubmit: () => void;
}) {
  const isBusy = isSubmitting || isCheckingBeforeSubmit;

  return (
    <DialogFooter>
      <Button variant="outline" onClick={onCancel} disabled={isBusy}>
        {__('Cancel', 'yayloyalty')}
      </Button>
      <Button onClick={onSubmit} disabled={isBusy || isPreviewLoading || !submitEnabled}>
        {isBusy
          ? __('Applying…', 'yayloyalty')
          : isSingle
            ? submitLabel(state.direction, formatPoints(state.amount), state.amount === 0)
            : bulkSubmitLabel(state.direction, formatPoints(state.amount), effectiveCount)}
      </Button>
    </DialogFooter>
  );
}
