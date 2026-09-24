import { useState } from 'react';
import { toast } from 'sonner';

import { Can, CAP } from '@/lib/access';
import { getApiErrorMessage } from '@/lib/api/api';
import { IS_PRO_BUILD } from '@/lib/is-pro-build';
import { useAdjustPointsMutation, useCustomerQuery } from '@/lib/queries/customer.queries';
import type { Customer } from '@/lib/schema/customer.schema';
import { Button } from '@/components/ui/button';
import { ADJUST_POINTS_ERROR_FALLBACK, adjustPointsSuccessToast } from '../components/customers-adjust-points.copy';
import { CustomersAdjustPointsDialog } from '../components/CustomersAdjustPointsDialog';
import {
  ACTION_ADJUST_POINTS,
  ACTION_ASSIGN_TIER,
  ACTION_EXPORT_LEDGER,
  ACTION_RESET_EXPIRY,
  DELETED_ACCOUNT_ACTION_REASON,
  NOT_AVAILABLE_YET_REASON,
  RESET_EXPIRY_NEEDS_POINTS_REASON,
} from './customer-drawer.copy';

/**
 * Adjust points · Reset expiry · Export ledger · [Assign tier, Pro only].
 * Adjust points is the one live action — it reuses the existing bulk
 * dialog/mutation in its single-customer layout, which already invalidates detail,
 * ledger, activity and list on success (see `useAdjustPointsMutation`), so
 * the drawer's own stats refresh without any extra wiring here.
 *
 * The other three have no endpoint yet and are `disabled` — one shared line
 * beneath the row explains why, rather than four separate tooltips (a wall
 * of identical-looking dead buttons with no text reads as broken). Priority when more
 * than one reason could apply: a deleted account explains everything by
 * itself; otherwise a non-positive balance gets the more specific "needs
 * points to extend" line (it's really about Reset expiry, but doubles as
 * the row's explanation since nothing else needs a more specific one yet).
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
        {IS_PRO_BUILD && (
          <Can cap={CAP.MANAGE_TIERS} disable>
            <Button variant="outline" disabled>
              {ACTION_ASSIGN_TIER()}
            </Button>
          </Can>
        )}
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
