import { useState } from 'react';
import { HTTPError } from 'ky';
import { Outlet } from 'react-router';
import { toast } from 'sonner';

import { toastApiError } from '@/lib/api/api';
import { can, CAP } from '@/lib/access';
import { settleInChunks } from '@/lib/helpers/settle-in-chunks.helper';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { ErrorState } from '@/components/ui/custom/error-state';
import { Pagination } from '@/components/ui/pagination';
import { Skeleton } from '@/components/ui/skeleton';
import { useReferralDecisionMutation, useReferralListQuery } from '../referral.queries';
import { REFERRALS_COPY } from '../referrals.copy';
import type { ReferralListItem } from '../referrals.schema';
import { APPROVALS_COPY, type AdminRejectReason } from './approvals.copy';
import { ApprovalsTable } from './ApprovalsTable';
import { RejectReferralDialog } from './RejectReferralDialog';

const PAGE_SIZE = 20;

/** Whether a failed approve or reject means the referral had already moved on (the server's 409 `yayloyalty_referral_changed`). */
async function isChanged(error: unknown): Promise<boolean> {
  if (!(error instanceof HTTPError) || error.response.status !== 409) return false;

  const body: { code?: unknown } | null = await error.response.clone().json().catch(() => null);

  return body?.code === 'yayloyalty_referral_changed';
}

/**
 * Referral → Approvals: held referrals, oldest hold end first, approved or rejected one by one or in bulk.
 * Without CAP.MANAGE_CAMPAIGNS it is a read-only list.
 */
export function ApprovalsTab() {
  const canAct = can(CAP.MANAGE_CAMPAIGNS);
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [busy, setBusy] = useState<Set<number>>(new Set());
  const [rejecting, setRejecting] = useState<ReferralListItem[] | null>(null);
  const decide = useReferralDecisionMutation();
  const query = useReferralListQuery({ page, perPage: PAGE_SIZE, status: 'on_hold', orderby: 'hold_until' });
  const items = query.data?.data ?? [];
  const held = query.data?.counts.on_hold;
  const totalPages = Math.max(1, query.data?.totalPages ?? 1);
  const chosen = items.filter((item) => selected.has(item.id));

  async function run(targets: ReferralListItem[], reason?: AdminRejectReason) {
    const errors: unknown[] = [];
    setBusy(new Set(targets.map((item) => item.id)));

    const { succeeded } = await settleInChunks(targets, (item) =>
      decide.mutateAsync({ id: item.id, reason }).catch((error: unknown) => {
        errors.push(error);
        throw error;
      }),
    );

    setBusy(new Set());
    setSelected(new Set());
    setRejecting(null);

    if (succeeded > 0) {
      const one = targets.length === 1 ? targets[0] : null;
      toast.success(
        reason
          ? one
            ? APPROVALS_COPY.rejected()
            : APPROVALS_COPY.rejectedMany(succeeded)
          : one
            ? APPROVALS_COPY.paid(one.advocate_points, one.advocate.name)
            : APPROVALS_COPY.paidMany(succeeded),
      );
    }

    if (errors.length > 0) {
      if (await isChanged(errors[0])) toast.error(APPROVALS_COPY.changed());
      else await toastApiError(errors[0], APPROVALS_COPY.actionFailed());
    }
  }

  function toggle(id: number) {
    setSelected((current) => {
      const next = new Set(current);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }

  function toggleAll() {
    setSelected(chosen.length === items.length ? new Set() : new Set(items.map((item) => item.id)));
  }

  return (
    <>
      <Card>
        <CardContent className="flex flex-col gap-4">
          <div className="text-muted-foreground text-sm">
            {held === undefined ? <Skeleton className="h-4 w-64" /> : APPROVALS_COPY.description(held)}
          </div>

          {canAct && chosen.length > 0 && (
            <div className="flex items-center gap-2">
              <span className="text-sm font-semibold">{APPROVALS_COPY.selected(chosen.length)}</span>
              <Button size="sm" disabled={busy.size > 0} onClick={() => run(chosen)}>
                {APPROVALS_COPY.approveSelected()}
              </Button>
              <Button size="sm" variant="outline" disabled={busy.size > 0} onClick={() => setRejecting(chosen)}>
                {APPROVALS_COPY.rejectSelected()}
              </Button>
            </div>
          )}

          {query.isError ? (
            <ErrorState message={REFERRALS_COPY.loadError()} onRetry={() => query.refetch()} />
          ) : (
            <>
              <div className="overflow-x-auto rounded-md border">
                <ApprovalsTable
                  items={items}
                  isLoading={query.isPending}
                  canAct={canAct}
                  selected={selected}
                  busy={busy}
                  onToggle={toggle}
                  onToggleAll={toggleAll}
                  onApprove={(item) => run([item])}
                  onReject={(item) => setRejecting([item])}
                />
              </div>
              {totalPages > 1 && (
                <Pagination
                  pageIndex={page - 1}
                  pageCount={totalPages}
                  canPreviousPage={page > 1}
                  canNextPage={page < totalPages}
                  onPreviousPage={() => setPage(Math.max(1, page - 1))}
                  onNextPage={() => setPage(Math.min(totalPages, page + 1))}
                  onPageChange={(pageIndex) => setPage(pageIndex + 1)}
                  className="ml-auto"
                />
              )}
            </>
          )}

          <p className="text-muted-foreground text-xs">{APPROVALS_COPY.footer()}</p>
        </CardContent>
      </Card>

      {rejecting && (
        <RejectReferralDialog
          targets={rejecting}
          isPending={busy.size > 0}
          onConfirm={(reason) => run(rejecting, reason)}
          onClose={() => setRejecting(null)}
        />
      )}

      <Outlet />
    </>
  );
}
