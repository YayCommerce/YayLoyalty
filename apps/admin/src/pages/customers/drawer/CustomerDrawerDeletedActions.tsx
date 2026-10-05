import { Button } from '@/components/ui/button';
import { ACTION_ADJUST_POINTS, ACTION_EXPORT_LEDGER, ACTION_RESET_EXPIRY, DELETED_ACCOUNT_ACTION_REASON } from './customer-drawer.copy';
import {
  ACTION_ANONYMISE,
  DELETED_ANONYMISE_REASON,
  DELETED_EXPORT_REASON,
  DELETED_RESET_EXPIRY_REASON,
  WHAT_CAN_STILL_BE_DONE_TITLE,
} from './customer-drawer.panels.copy';

function DeletedActionRow({ label, reason }: { label: string; reason: string }) {
  return (
    <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between sm:gap-3">
      <Button variant="outline" size="sm" disabled className="w-fit">
        {label}
      </Button>
      <p className="text-muted-foreground text-xs">{reason}</p>
    </div>
  );
}

/**
 * What can still be done for a deleted customer. Each action is disabled for its own reason, so each row states it.
 * Export stays disabled, as in the main actions row: the drawer has no export job yet.
 */
export function CustomerDrawerDeletedActions() {
  return (
    <div className="flex flex-col gap-3 rounded-md border p-3.5">
      <p className="text-sm font-medium">{WHAT_CAN_STILL_BE_DONE_TITLE()}</p>
      <div className="flex flex-col gap-2.5">
        <DeletedActionRow label={ACTION_ADJUST_POINTS()} reason={DELETED_ACCOUNT_ACTION_REASON()} />
        <DeletedActionRow label={ACTION_RESET_EXPIRY()} reason={DELETED_RESET_EXPIRY_REASON()} />
        <DeletedActionRow label={ACTION_EXPORT_LEDGER()} reason={DELETED_EXPORT_REASON()} />
        <DeletedActionRow label={ACTION_ANONYMISE()} reason={DELETED_ANONYMISE_REASON()} />
      </div>
    </div>
  );
}
