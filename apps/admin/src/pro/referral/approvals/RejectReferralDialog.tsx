import { useId, useState } from 'react';

import { ConfirmDialog } from '@/components/ui/custom/confirm-dialog';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { REJECT_REASON_LABEL } from '../referrals.copy';
import type { ReferralListItem } from '../referrals.schema';
import { ADMIN_REJECT_REASONS, APPROVALS_COPY, type AdminRejectReason } from './approvals.copy';

/** Asks for one reason for every referral in `targets`. Mount it only while open, so each decision starts fresh. */
export function RejectReferralDialog({
  targets,
  isPending,
  onConfirm,
  onClose,
}: {
  targets: ReferralListItem[];
  isPending: boolean;
  onConfirm: (reason: AdminRejectReason) => void;
  onClose: () => void;
}) {
  const [reason, setReason] = useState<AdminRejectReason>('not_genuine');
  const labelId = useId();
  const single = targets.length === 1 ? targets[0] : null;

  return (
    <ConfirmDialog
      open
      onOpenChange={(open) => !open && !isPending && onClose()}
      title={single ? APPROVALS_COPY.dialogTitle() : APPROVALS_COPY.dialogTitleMany()}
      description={
        single
          ? APPROVALS_COPY.dialogBody(single.advocate_points, single.advocate.name)
          : APPROVALS_COPY.dialogBodyMany(targets.length)
      }
      confirmLabel={APPROVALS_COPY.reject()}
      cancelLabel={APPROVALS_COPY.cancel()}
      loadingLabel={APPROVALS_COPY.rejecting()}
      variant="destructive"
      isLoading={isPending}
      onConfirm={() => onConfirm(reason)}
    >
      <div className="flex flex-col gap-2">
        <Label id={labelId}>{APPROVALS_COPY.reasonLabel()}</Label>
        <Select value={reason} onValueChange={(value) => setReason(value as AdminRejectReason)}>
          <SelectTrigger aria-labelledby={labelId} autoFocus>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {ADMIN_REJECT_REASONS.map((value) => (
              <SelectItem key={value} value={value}>
                {REJECT_REASON_LABEL[value]()}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>
    </ConfirmDialog>
  );
}
