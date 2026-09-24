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
 * 4a's "What can still be done" — four actions, each disabled for a
 * genuinely DIFFERENT reason. Deliberately NOT `CustomerDrawerActions.tsx`'s
 * single-shared-reason-line shape: that pattern exists because its three
 * disabled buttons share one cause ("not available yet"); here every button
 * has its own, so a shared line would either repeat itself four times or
 * flatten four distinct facts into one vague sentence. Nothing extracted
 * between the two components — they are different shapes on purpose.
 *
 * Export stays disabled here too, even though 4a's own artboard draws it
 * live — this drawer has no export job anywhere yet (the main actions row's
 * Export is disabled for the same reason), so 4a doesn't get a
 * special live button the rest of the drawer doesn't have.
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
