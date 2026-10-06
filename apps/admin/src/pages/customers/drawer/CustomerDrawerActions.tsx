import { useState } from 'react';
import { toast } from 'sonner';

import { Can, CAP } from '@/lib/access';
import { getApiErrorMessage } from '@/lib/api/api';
import { useAdjustPointsMutation, useCustomerQuery } from '@/lib/queries/customer.queries';
import type { Customer } from '@/lib/schema/customer.schema';
import { Button } from '@/components/ui/button';
import { ADJUST_POINTS_ERROR_FALLBACK, adjustPointsSuccessToast } from '../components/customers-adjust-points.copy';
import { CustomersAdjustPointsDialog } from '../components/CustomersAdjustPointsDialog';
import {
  ACTION_ADJUST_POINTS,
  ACTION_EXPORT_LEDGER,
  ACTION_RESET_EXPIRY,
  DELETED_ACCOUNT_ACTION_REASON,
  NOT_AVAILABLE_YET_REASON,
  RESET_EXPIRY_NEEDS_POINTS_REASON,
} from './customer-drawer.copy';

/**
 * The drawer's actions. Adjust points is the one live action, and its mutation's invalidations refresh the drawer.
 * The others have no endpoint yet. One shared line explains why, since dead buttons with no text read as broken.
 * A deleted account's reason wins, then the non-positive balance one.
 */
export function CustomerDrawerActions({ customer, isLoading }: { customer: Customer; isLoading: boolean }) {
  const [isAdjustOpen, setIsAdjustOpen] = useState(false);
  const adjustMutation = useAdjustPointsMutation(customer.user_id);
  const isDeleted = !!customer.deleted_at;

  // The drawer's own detail query (same key, same cache): the dialog reads and re-checks the record the drawer shows.
  const customerQuery = useCustomerQuery(customer.user_id);

  const disabledReason = isDeleted
    ? DELETED_ACCOUNT_ACTION_REASON()
    : customer.balance <= 0
      ? RESET_EXPIRY_NEEDS_POINTS_REASON()
      : NOT_AVAILABLE_YET_REASON();

  return (
    <div className="flex flex-col gap-2">
      <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
        <Can cap={CAP.MANAGE_POINTS} disable>
          <Button disabled={isLoading || isDeleted} onClick={() => setIsAdjustOpen(true)}>
            {ACTION_ADJUST_POINTS()}
          </Button>
        </Can>
        <Button variant="outline" disabled>
          {ACTION_RESET_EXPIRY()}
        </Button>
        <Button variant="outline" disabled>
          {ACTION_EXPORT_LEDGER()}
        </Button>
      </div>
      <p className="text-muted-foreground text-xs">{disabledReason}</p>

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
              toast.error(await getApiErrorMessage(error, ADJUST_POINTS_ERROR_FALLBACK()));
            },
          })
        }
        target={{ kind: 'single', customer: customerQuery }}
      />
    </div>
  );
}
