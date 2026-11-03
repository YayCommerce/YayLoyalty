import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router';
import { toast } from 'sonner';

import { Can, CAP, useCapability } from '@/lib/access';
import { toastApiError } from '@/lib/api/api';
import { useAdjustPointsMutation, useCustomerQuery } from '@/lib/queries/customer.queries';
import type { Customer } from '@/lib/schema/customer.schema';
import { Button } from '@/components/ui/button';
import { ADJUST_POINTS_ERROR_FALLBACK, adjustPointsSuccessToast } from '../components/customers-adjust-points.copy';
import { CustomersAdjustPointsDialog } from '../components/CustomersAdjustPointsDialog';
import { ACTION_ADJUST_POINTS, DELETED_ACCOUNT_ACTION_REASON } from './customer-drawer.copy';
import { CustomerDrawerExportLedgerButton } from './CustomerDrawerExportLedgerButton';

/**
 * The drawer's actions. Adjust points and Export ledger are live; Adjust points' invalidations refresh the drawer.
 * On a deleted account a line under the row says why Adjust points is off. Export works for a deleted account too.
 * `?adjust=1` opens Adjust points once, then leaves the URL; `&points=N` prefills the amount. Both parameters
 * are owned by OrderPointsMetabox::adjust_points_link() (PHP), the order screen's "Adjust points →" link.
 */
export function CustomerDrawerActions({ customer, isLoading }: { customer: Customer; isLoading: boolean }) {
  const [isAdjustOpen, setIsAdjustOpen] = useState(false);
  const [initialAmount, setInitialAmount] = useState<number | undefined>(undefined);
  const adjustMutation = useAdjustPointsMutation(customer.user_id);
  const isDeleted = !!customer.deleted_at;
  const canAdjust = useCapability(CAP.MANAGE_POINTS);
  const [searchParams, setSearchParams] = useSearchParams();
  const wantsAdjust = searchParams.get('adjust') === '1';
  const linkedPoints = Number(searchParams.get('points'));

  useEffect(() => {
    if (!wantsAdjust) return;

    // Dropped first, so closing the dialog or reloading never reopens it, and the drawer's list filters never see it.
    setSearchParams(
      (params) => {
        params.delete('adjust');
        params.delete('points');
        return params;
      },
      { replace: true },
    );

    if (canAdjust && !isDeleted) {
      setInitialAmount(Number.isInteger(linkedPoints) && linkedPoints > 0 ? linkedPoints : undefined);
      setIsAdjustOpen(true);
    }
  }, [wantsAdjust, linkedPoints, canAdjust, isDeleted, setSearchParams]);

  // The drawer's own detail query (same key, same cache): the dialog reads and re-checks the record the drawer shows.
  const customerQuery = useCustomerQuery(customer.user_id);

  return (
    <div className="flex flex-col gap-2">
      <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
        <Can cap={CAP.MANAGE_POINTS} disable>
          <Button disabled={isLoading || isDeleted} onClick={() => setIsAdjustOpen(true)}>
            {ACTION_ADJUST_POINTS()}
          </Button>
        </Can>
        <CustomerDrawerExportLedgerButton userId={customer.user_id} />
      </div>
      {isDeleted && <p className="text-muted-foreground text-xs">{DELETED_ACCOUNT_ACTION_REASON()}</p>}

      <CustomersAdjustPointsDialog
        open={isAdjustOpen}
        onOpenChange={setIsAdjustOpen}
        count={1}
        isSubmitting={adjustMutation.isPending}
        onSubmit={(input) =>
          adjustMutation.mutate(input, {
            onSuccess: () => {
              setIsAdjustOpen(false);
              toast.success(adjustPointsSuccessToast(customer.name));
            },
            onError: async (error) => {
              await toastApiError(error, ADJUST_POINTS_ERROR_FALLBACK());
            },
          })
        }
        target={{ kind: 'single', customer: customerQuery }}
        initialAmount={initialAmount}
      />
    </div>
  );
}
