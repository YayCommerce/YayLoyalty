import { Button } from '@/components/ui/button';
import {
  ACTION_LEAVE_AS_IS,
  ACTION_WRITE_OFF_NEGATIVE_BALANCE,
  negativeWriteOffConsequence,
} from './customer-drawer.panels.copy';

/**
 * Write off or leave as is, with no default. Both are disabled.
 * TODO(negative-balance): wire "Write off" as `POST /customers/{id}/adjust` with `points: +deficit` once its note and undo rule are decided.
 */
export function CustomerDrawerNegativeActions({ customerFirstName }: { customerFirstName: string }) {
  return (
    <div className="flex flex-col gap-2">
      <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
        <Button variant="outline" size="sm" disabled>
          {ACTION_WRITE_OFF_NEGATIVE_BALANCE()}
        </Button>
        <Button variant="outline" size="sm" disabled>
          {ACTION_LEAVE_AS_IS()}
        </Button>
      </div>
      <p className="text-muted-foreground text-xs">{negativeWriteOffConsequence(customerFirstName)}</p>
    </div>
  );
}
