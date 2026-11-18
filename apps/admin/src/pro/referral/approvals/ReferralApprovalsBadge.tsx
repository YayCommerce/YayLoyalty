import { formatCount } from '@/lib/helpers/points.helper';
import { cn } from '@/lib/utils';
import { useHeldReferralCount } from '../referral.queries';
import { APPROVALS_COPY } from './approvals.copy';

/** The number of held referrals beside the Approvals tab and the Referral header item. Hidden at 0. */
export function ReferralApprovalsBadge({ className }: { className?: string }) {
  const count = useHeldReferralCount();

  if (count < 1) return null;

  return (
    <span
      aria-label={APPROVALS_COPY.tabWaiting(count)}
      className={cn('bg-primary text-primary-foreground rounded-full px-1.5 text-[11px]/4 tabular-nums', className)}
    >
      {formatCount(count)}
    </span>
  );
}
