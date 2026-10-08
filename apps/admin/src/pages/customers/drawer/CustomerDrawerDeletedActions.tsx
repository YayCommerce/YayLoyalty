import type { ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import { ACTION_ADJUST_POINTS, ACTION_EXPORT_LEDGER, DELETED_ACCOUNT_ACTION_REASON } from './customer-drawer.copy';
import {
  ACTION_ANONYMISE,
  DELETED_ANONYMISE_REASON,
  DELETED_EXPORT_REASON,
  WHAT_CAN_STILL_BE_DONE_TITLE,
} from './customer-drawer.panels.copy';
import { CustomerDrawerExportLedgerButton } from './CustomerDrawerExportLedgerButton';

/** One action and its reason. A live `action` replaces the disabled `label` button. */
function DeletedActionRow({ label, reason, action }: { label: string; reason: string; action?: ReactNode }) {
  return (
    <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between sm:gap-3">
      <div className="w-fit">
        {action ?? (
          <Button variant="outline" size="sm" disabled>
            {label}
          </Button>
        )}
      </div>
      <p className="text-muted-foreground text-xs">{reason}</p>
    </div>
  );
}

/**
 * What can still be done for a deleted customer. Each disabled action states its own reason.
 * Export ledger is live, as in the main actions row: a deleted customer's history still matters.
 */
export function CustomerDrawerDeletedActions({ userId }: { userId: number }) {
  return (
    <div className="flex flex-col gap-3 rounded-md border p-3.5">
      <p className="text-sm font-medium">{WHAT_CAN_STILL_BE_DONE_TITLE()}</p>
      <div className="flex flex-col gap-2.5">
        <DeletedActionRow label={ACTION_ADJUST_POINTS()} reason={DELETED_ACCOUNT_ACTION_REASON()} />
        <DeletedActionRow
          label={ACTION_EXPORT_LEDGER()}
          reason={DELETED_EXPORT_REASON()}
          action={<CustomerDrawerExportLedgerButton userId={userId} size="sm" />}
        />
        <DeletedActionRow label={ACTION_ANONYMISE()} reason={DELETED_ANONYMISE_REASON()} />
      </div>
    </div>
  );
}
