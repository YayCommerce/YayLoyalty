import { useState } from 'react';
import { toast } from 'sonner';

import { Can, CAP } from '@/lib/access';
import { toastApiError } from '@/lib/api/api';
import { useAdjustPointsMutation, useCustomerQuery } from '@/lib/queries/customer.queries';
import type { Customer } from '@/lib/schema/customer.schema';
import { Button } from '@/components/ui/button';
import { YayLoyaltyToolTip } from '@/components/ui/custom/tooltip';
import { ADJUST_POINTS_ERROR_FALLBACK, adjustPointsSuccessToast } from '../components/customers-adjust-points.copy';
import { CustomersAdjustPointsDialog } from '../components/CustomersAdjustPointsDialog';
import { ACTION_ADJUST_POINTS, DELETED_ACCOUNT_ACTION_REASON } from './customer-drawer.copy';
import { CustomerDrawerExportLedgerButton } from './CustomerDrawerExportLedgerButton';

/**
 * The ledger toolbar's actions: Adjust points and an icon-only Export ledger. Adjust points' invalidations refresh
 * the drawer. On a deleted account its tooltip says why Adjust points is off. Export works for a deleted account too.
 */
export function CustomerDrawerActions({ customer }: { customer: Customer }) {
  const [isAdjustOpen, setIsAdjustOpen] = useState(false);
  const adjustMutation = useAdjustPointsMutation(customer.user_id);
  const isDeleted = !!customer.deleted_at;

  // The drawer's own detail query (same key, same cache): the dialog reads and re-checks the record the drawer shows.
  const customerQuery = useCustomerQuery(customer.user_id);

  return (
    <>
      <Can cap={CAP.MANAGE_POINTS} disable>
        {isDeleted ? (
          <YayLoyaltyToolTip
            content={DELETED_ACCOUNT_ACTION_REASON()}
            trigger={
              <span className="inline-flex">
                <Button disabled>{ACTION_ADJUST_POINTS()}</Button>
              </span>
            }
          />
        ) : (
          <Button disabled={customerQuery.isFetching} onClick={() => setIsAdjustOpen(true)}>
            {ACTION_ADJUST_POINTS()}
          </Button>
        )}
      </Can>
      <CustomerDrawerExportLedgerButton userId={customer.user_id} iconOnly />

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
      />
    </>
  );
}
